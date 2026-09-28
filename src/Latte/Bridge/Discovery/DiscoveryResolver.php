<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Bridge\Discovery;

use Nette\Application\Helpers;
use Nette\Application\IPresenterFactory;
use Nette\Application\PresenterFactory;
use Nette\Application\UI\Presenter as UiPresenter;
use Nette\DI\Container;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Latte\Bridge\MutationFact;
use OriPhpstan\Nette\Latte\Bridge\SetFileFact;
use PHPStan\Reflection\ClassReflection;
use ReflectionClass;
use ReflectionProperty;
use Throwable;
use function array_diff_key;
use function array_flip;
use function array_shift;
use function array_values;
use function explode;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function ltrim;
use function preg_match;
use function preg_replace;
use function realpath;
use function str_replace;
use function strlen;
use function strncmp;
use function substr;
use const DIRECTORY_SEPARATOR;

/**
 * @phpstan-type SetFileEntry array{kind: SetFileFact::KIND_*, path: string|null, certainty: Certainty::*, line: int, phase: MutationFact::PHASE_*, effectiveness: MutationFact::EFFECTIVE_*, scope: 'view'|'class'|'open', scopeView: string|null, conventionMethod: string|null, method: string|null}
 * @phpstan-type MappingValue array<string, array{string, string, string}>
 * @phpstan-type EntryCandidate array{scopeView: string|null, path: string|null, fallbackPath: string|null, kind: CandidatePath::KIND_SET_FILE|CandidatePath::KIND_CONVENTION, suppressing: bool, phase: MutationFact::PHASE_*, line: int, method: string|null}
 */
final class DiscoveryResolver
{

	// The opaque reason prefix for a presenter no configured mapping reverse-maps; the class name follows.
	public const UNRESOLVED_PRESENTER_REASON = 'presenter name unresolved: no mapping reverse-maps class ';

	private const FORMAT_TEMPLATE_FILES_METHOD = 'formatTemplateFiles';

	private const FORMAT_LAYOUT_TEMPLATE_FILES_METHOD = 'formatLayoutTemplateFiles';

	private ?string $containerLoaderFile;

	/** @var array<string, string|array<string, string>> */
	private array $formulas;

	private string $projectRootPath;

	private bool $mappingResolved = false;

	/** @var MappingValue|null */
	private ?array $mapping = null;

	/**
	 * @param array<string, string|array<string, string>> $formulas
	 */
	public function __construct(?string $containerLoaderFile, array $formulas, string $projectRootPath)
	{
		$this->containerLoaderFile = $containerLoaderFile;
		$this->formulas = $formulas;

		$real = realpath($projectRootPath);
		$this->projectRootPath = $real === false ? $projectRootPath : $real;
	}

	// The raw configured assignment map - like the resolved mapping, a live config input no
	// read-set file reflects, so the PhpFactsCache envelope stores and value-compares it.

	/**
	 * @return array<string, string|array<string, string>>
	 */
	public function getFormulas(): array
	{
		return $this->formulas;
	}

	// Same optional dic-style container-loader seam as TemplateFactoryDefaultResolver: any failure
	// on the way to a Nette PresenterFactory degrades to null, which makes every presenter's
	// discovery opaque (never guessed). The RESOLVED value is what the PhpFactsCache envelope
	// compares - no read-set file reflects the live container.

	/**
	 * @return MappingValue|null
	 */
	public function resolveMapping(): ?array
	{
		if (!$this->mappingResolved) {
			$this->mappingResolved = true;
			$this->mapping = $this->doResolveMapping();
		}

		return $this->mapping;
	}

	/**
	 * @param list<string> $viewNames
	 * @param list<SetFileEntry> $setFileEntries
	 */
	public function resolve(
		ClassReflection $entryClass,
		bool $presenter,
		array $viewNames,
		array $setFileEntries
	): DiscoveryFact
	{
		$classFile = $entryClass->getFileName();
		if ($classFile === null) {
			return new DiscoveryFact([], [], [], []);
		}

		// Each probe is recorded under (path, kind) with the same function the envelope re-probes
		// with: is_file for candidate gates (the vendor and app locators both gate on is_file),
		// is_dir for the dir-adjustment branch - a dir/file swap under either name flips the result -
		// and a listing digest for the directories the formulas' reverse operation enumerates, whose
		// contents no stat reflects.
		/** @var array<string, array{path: string, kind: DiscoveryFact::PROBE_*, result: bool|string}> $existence */
		$existence = [];
		$probeFile = static function (string $path) use (&$existence): bool {
			$result = is_file($path);
			$existence[$path . "\0" . DiscoveryFact::PROBE_FILE] = [
				'path' => $path,
				'kind' => DiscoveryFact::PROBE_FILE,
				'result' => $result,
			];

			return $result;
		};
		$probeDir = static function (string $path) use (&$existence): bool {
			$result = is_dir($path);
			$existence[$path . "\0" . DiscoveryFact::PROBE_DIR] = [
				'path' => $path,
				'kind' => DiscoveryFact::PROBE_DIR,
				'result' => $result,
			];

			return $result;
		};
		$probeListing = static function (string $path) use (&$existence): array {
			$names = TemplateDirectoryListing::names($path);
			$existence[$path . "\0" . DiscoveryFact::PROBE_LISTING] = [
				'path' => $path,
				'kind' => DiscoveryFact::PROBE_LISTING,
				'result' => TemplateDirectoryListing::digestOf($names),
			];

			return $names;
		};

		/** @var list<array{reason: string, line: int|null}> $opaques */
		$opaques = [];

		$presenterSegment = null;
		$module = null;
		$viewFormula = null;
		$layoutCandidates = [];

		if ($presenter) {
			$name = $this->presenterNameFor($entryClass->getName());
			if ($name === null) {
				$opaques[] = [
					'reason' => self::UNRESOLVED_PRESENTER_REASON . $entryClass->getName(),
					'line' => null,
				];
			} else {
				[$module, $presenterSegment] = Helpers::splitName($name);

				$viewFormula = $this->recognizedFormula(
					$entryClass,
					self::FORMAT_TEMPLATE_FILES_METHOD,
					FormulaVocabulary::VENDOR_TWO_CANDIDATE,
					FormulaVocabulary::VIEW_FORMULAS,
					$opaques,
				);

				$layoutFormula = $this->recognizedFormula(
					$entryClass,
					self::FORMAT_LAYOUT_TEMPLATE_FILES_METHOD,
					FormulaVocabulary::VENDOR_LAYOUT_WALK,
					[FormulaVocabulary::VENDOR_LAYOUT_WALK],
					$opaques,
				);
				if ($layoutFormula !== null) {
					$layoutCandidates = $this->layoutCandidatePaths(
						FormulaVocabulary::layoutCandidates($classFile, $module, $presenterSegment, $probeDir),
						$probeFile,
					);
				}
			}
		}

		$entryCandidates = $this->entryCandidates($entryClass, $presenter, $setFileEntries, $opaques);

		$viewCandidates = $this->assembleViewCandidates(
			$presenter,
			self::withFileDerivedViews(
				$viewNames,
				$entryCandidates,
				$viewFormula,
				$classFile,
				$presenterSegment,
				$probeDir,
				$probeListing,
			),
			$entryCandidates,
			$viewFormula,
			$classFile,
			$presenterSegment,
			$probeFile,
			$probeDir,
		);

		// Existence-set paths stay absolute on purpose: the PhpFactsCache envelope re-probes each
		// (path, kind) entry verbatim with the kind's own function, the same way readSetHashes keys
		// are absolute per-machine paths.
		return new DiscoveryFact($viewCandidates, $layoutCandidates, $opaques, array_values($existence));
	}

	/**
	 * In Nette BOTH dispatch methods are optional: a template file sitting where the presenter's own
	 * formula would put it renders on request with no action<View>/render<View> anywhere. The
	 * method-derived view set therefore UNDER-approximates the renderable set, and the formulas'
	 * reverse operation closes the gap - strictly by ADDING views, which can only add liveness.
	 * Skipped when a proven class-wide setFile already suppresses the formula for every view: the
	 * formula's own output never reaches the renderer there, so a file at its path proves nothing.
	 *
	 * @param list<string> $viewNames
	 * @param list<EntryCandidate> $entryCandidates
	 * @param callable(string): bool $probeDir
	 * @param callable(string): list<string> $probeListing
	 * @return list<string>
	 */
	private static function withFileDerivedViews(
		array $viewNames,
		array $entryCandidates,
		?string $viewFormula,
		string $classFile,
		?string $presenterSegment,
		callable $probeDir,
		callable $probeListing
	): array
	{
		if ($viewFormula === null || $presenterSegment === null) {
			return $viewNames;
		}

		foreach ($entryCandidates as $candidate) {
			if ($candidate['scopeView'] === null && $candidate['suppressing']) {
				return $viewNames;
			}
		}

		$offered = FormulaVocabulary::offeredViews(
			$viewFormula,
			$classFile,
			$presenterSegment,
			$probeDir,
			$probeListing,
		);
		if ($offered === null) {
			return $viewNames;
		}

		foreach ($offered as $view) {
			if (!in_array($view, $viewNames, true)) {
				$viewNames[] = $view;
			}
		}

		return $viewNames;
	}

	/**
	 * Candidate seeds derived from setFile entries: 'view' scoped seeds land only on their view,
	 * everything else lands on every view (the conservative union). A seed suppresses the formula
	 * candidates of its scope only when the write provably runs and provably precedes the
	 * template-file resolution: unconditional (HAPPENS), EFFECTIVE_YES and lexically inside a
	 * dispatch method whose lifecycle window is proven (an action or render method for its own
	 * view, startup/checkRequirements/beforeRender class-wide). Everything else - conditional writes,
	 * helpers, signals, outside phases - records its candidate without suppressing.
	 *
	 * @param list<SetFileEntry> $setFileEntries
	 * @param list<array{reason: string, line: int|null}> $opaques
	 * @return list<EntryCandidate>
	 */
	private function entryCandidates(
		ClassReflection $entryClass,
		bool $presenter,
		array $setFileEntries,
		array &$opaques
	): array
	{
		$candidates = [];
		foreach ($setFileEntries as $entry) {
			$suppressing = $entry['certainty'] === Certainty::HAPPENS
				&& $entry['effectiveness'] === MutationFact::EFFECTIVE_YES
				&& (!$presenter || $entry['scope'] !== 'open');

			$scopeView = $presenter && $entry['scope'] === 'view' ? $entry['scopeView'] : null;

			if ($entry['kind'] === SetFileFact::KIND_LITERAL) {
				$candidates[] = [
					'scopeView' => $scopeView,
					'path' => $entry['path'],
					'fallbackPath' => null,
					'kind' => CandidatePath::KIND_SET_FILE,
					'suppressing' => $suppressing,
					'phase' => $entry['phase'],
					'line' => $entry['line'],
					'method' => $entry['method'],
				];

				continue;
			}

			if ($entry['kind'] === SetFileFact::KIND_CONVENTION) {
				$conventionPaths = $this->conventionPathsFor(
					$entryClass,
					$entry['conventionMethod'],
					$entry['line'],
					$opaques,
				);
				$candidates[] = [
					'scopeView' => $scopeView,
					'path' => $conventionPaths['path'],
					'fallbackPath' => $conventionPaths['fallbackPath'],
					'kind' => CandidatePath::KIND_CONVENTION,
					'suppressing' => $suppressing,
					'phase' => $entry['phase'],
					'line' => $entry['line'],
					'method' => $entry['method'],
				];

				continue;
			}

			// KIND_OPAQUE: the write is real (still suppresses when proven), the path is not
			// derivable - recorded as an opaque hole instead of a candidate.
			$opaques[] = [
				'reason' => 'setFile argument is not statically resolvable',
				'line' => $entry['line'],
			];
			$candidates[] = [
				'scopeView' => $scopeView,
				'path' => null,
				'fallbackPath' => null,
				'kind' => CandidatePath::KIND_SET_FILE,
				'suppressing' => $suppressing,
				'phase' => $entry['phase'],
				'line' => $entry['line'],
				'method' => $entry['method'],
			];
		}

		return $candidates;
	}

	/**
	 * @param list<string> $viewNames
	 * @param list<EntryCandidate> $entryCandidates
	 * @param callable(string): bool $probeFile
	 * @param callable(string): bool $probeDir
	 * @return array<array-key, list<CandidatePath>>
	 */
	private function assembleViewCandidates(
		bool $presenter,
		array $viewNames,
		array $entryCandidates,
		?string $viewFormula,
		string $classFile,
		?string $presenterSegment,
		callable $probeFile,
		callable $probeDir
	): array
	{
		// Controls have no view axis and a viewless presenter still needs a home for class-level
		// writes: the empty-string bucket.
		if ($viewNames === []) {
			$viewNames = $entryCandidates === [] ? [] : [''];
		}

		$phaseOrder = array_flip(MutationFact::LIFECYCLE_PHASE_ORDER);

		$viewCandidates = [];
		foreach ($viewNames as $view) {
			$applicable = [];
			foreach ($entryCandidates as $candidate) {
				if ($candidate['scopeView'] === null || $candidate['scopeView'] === $view) {
					$applicable[] = $candidate;
				}
			}

			// Presenters: one runtime winner per view bucket - the LAST proven write before
			// resolution (latest phase, then latest line). Controls: every render method is its own
			// entry point ({control x} vs {control x:y}), so the last write wins PER METHOD.
			$winners = [];
			foreach ($applicable as $index => $candidate) {
				if (!$candidate['suppressing']) {
					continue;
				}

				$group = $presenter ? '' : ($candidate['method'] ?? '');
				$current = $winners[$group] ?? null;
				if (
					$current === null
					|| $phaseOrder[$candidate['phase']] > $phaseOrder[$applicable[$current]['phase']]
					|| ($phaseOrder[$candidate['phase']] === $phaseOrder[$applicable[$current]['phase']]
						&& $candidate['line'] > $applicable[$current]['line'])
				) {
					$winners[$group] = $index;
				}
			}

			$winnerIndexes = array_flip($winners);

			$list = [];
			$chosenTaken = false;
			foreach ($applicable as $index => $candidate) {
				if ($candidate['path'] === null) {
					continue;
				}

				$chosen = isset($winnerIndexes[$index]);
				if ($chosen) {
					$chosenTaken = true;
				}

				$exists = $probeFile($candidate['path']);

				if ($candidate['fallbackPath'] === null) {
					$list[] = new CandidatePath(
						self::relativized($candidate['path'], $this->projectRootPath),
						$exists,
						$chosen,
						$candidate['kind'],
					);

					continue;
				}

				// The shared-fallback locators' gate: the derived path when it is a file, else the
				// existing shared fallback, else the derived path anyway.
				$fallbackExists = $probeFile($candidate['fallbackPath']);
				$list[] = new CandidatePath(
					self::relativized($candidate['path'], $this->projectRootPath),
					$exists,
					$chosen && ($exists || !$fallbackExists),
					$candidate['kind'],
				);
				$list[] = new CandidatePath(
					self::relativized($candidate['fallbackPath'], $this->projectRootPath),
					$fallbackExists,
					$chosen && !$exists && $fallbackExists,
					$candidate['kind'],
				);
			}

			$suppressed = $winners !== [];

			if (
				$presenter
				&& $view !== ''
				&& !$suppressed
				&& $viewFormula !== null
				&& $presenterSegment !== null
			) {
				/** @var FormulaVocabulary::VENDOR_TWO_CANDIDATE|FormulaVocabulary::SAMEDIR_SINGLE $viewFormula */
				$paths = FormulaVocabulary::viewCandidates(
					$viewFormula,
					$classFile,
					$presenterSegment,
					$view,
					$probeDir,
				);
				foreach ($paths as $path) {
					$exists = $probeFile($path);
					$chosen = !$chosenTaken && $exists;
					if ($chosen) {
						$chosenTaken = true;
					}

					$list[] = new CandidatePath(
						self::relativized($path, $this->projectRootPath),
						$exists,
						$chosen,
						CandidatePath::KIND_FORMULA,
					);
				}
			}

			$viewCandidates[$view] = $list;
		}

		return $viewCandidates;
	}

	/**
	 * Recognition is by DECLARING CLASS of the RESOLVED method only - a dead overridden ancestor
	 * body never contributes. Native reflection reports a trait-declared method with the USING
	 * class as its declaring class but the trait's file as getFileName(), so the identity is
	 * re-attributed to the declaring trait by file (the config key is the trait's FQCN).
	 *
	 * @param list<string> $applicableFormulas
	 * @param list<array{reason: string, line: int|null}> $opaques
	 */
	private function recognizedFormula(
		ClassReflection $entryClass,
		string $methodName,
		string $vendorFormula,
		array $applicableFormulas,
		array &$opaques
	): ?string
	{
		$resolved = self::declaringIdentity($entryClass, $methodName);
		if ($resolved === null) {
			$opaques[] = [
				'reason' => "$methodName is not resolvable on " . $entryClass->getName(),
				'line' => null,
			];

			return null;
		}

		$identity = $resolved['identity'];
		if ($identity === UiPresenter::class) {
			return $vendorFormula;
		}

		$entry = $this->formulas[$identity] ?? null;
		if ($entry === null) {
			$opaques[] = [
				'reason' => "$methodName override declared by $identity has no assigned discovery formula",
				'line' => null,
			];

			return null;
		}

		$assignment = self::normalizedAssignment($entry);
		if ($assignment === null) {
			$opaques[] = [
				'reason' => "invalid discovery formula assignment for $identity",
				'line' => null,
			];

			return null;
		}

		$assigned = $assignment['formula'];
		if (
			$assignment['sharedFallback'] !== null
			&& $assigned !== FormulaVocabulary::DIRNAME_TEMPLATES_LCFIRST_FALLBACK
		) {
			$opaques[] = [
				'reason' => "formula $assigned assigned to $identity does not take a sharedFallback",
				'line' => null,
			];

			return null;
		}

		if (
			$assignment['nameProperty'] !== null
			&& $assigned !== FormulaVocabulary::DIRNAME_PROPERTY_LCFIRST
		) {
			$opaques[] = [
				'reason' => "formula $assigned assigned to $identity does not take a nameProperty",
				'line' => null,
			];

			return null;
		}

		if (!in_array($assigned, $applicableFormulas, true)) {
			$reason = FormulaVocabulary::isKnown($assigned)
				? "formula $assigned assigned to $identity is not applicable to $methodName"
				: "unknown discovery formula $assigned assigned to $identity";
			$opaques[] = ['reason' => $reason, 'line' => null];

			return null;
		}

		return $assigned;
	}

	/**
	 * @param list<array{reason: string, line: int|null}> $opaques
	 * @return array{path: string|null, fallbackPath: string|null}
	 */
	private function conventionPathsFor(
		ClassReflection $entryClass,
		?string $conventionMethod,
		int $line,
		array &$opaques
	): array
	{
		$none = ['path' => null, 'fallbackPath' => null];

		if ($conventionMethod === null) {
			$opaques[] = [
				'reason' => 'convention setFile argument method is not statically named',
				'line' => $line,
			];

			return $none;
		}

		$resolved = self::declaringIdentity($entryClass, $conventionMethod);
		if ($resolved === null) {
			$opaques[] = [
				'reason' => "convention method $conventionMethod is not resolvable on " . $entryClass->getName(),
				'line' => $line,
			];

			return $none;
		}

		$identity = $resolved['identity'];
		$entry = $this->formulas[$identity] ?? null;
		if ($entry === null) {
			$opaques[] = [
				'reason' => "convention method $conventionMethod declared by $identity has no assigned discovery formula",
				'line' => $line,
			];

			return $none;
		}

		$assignment = self::normalizedAssignment($entry);
		if ($assignment === null) {
			$opaques[] = [
				'reason' => "invalid discovery formula assignment for $identity",
				'line' => $line,
			];

			return $none;
		}

		$assigned = $assignment['formula'];
		$sharedFallback = $assignment['sharedFallback'];
		if ($sharedFallback !== null && $assigned !== FormulaVocabulary::DIRNAME_TEMPLATES_LCFIRST_FALLBACK) {
			$opaques[] = [
				'reason' => "formula $assigned assigned to $identity does not take a sharedFallback",
				'line' => $line,
			];

			return $none;
		}

		$nameProperty = $assignment['nameProperty'];
		if ($nameProperty !== null && $assigned !== FormulaVocabulary::DIRNAME_PROPERTY_LCFIRST) {
			$opaques[] = [
				'reason' => "formula $assigned assigned to $identity does not take a nameProperty",
				'line' => $line,
			];

			return $none;
		}

		if (!in_array($assigned, FormulaVocabulary::CONVENTION_FORMULAS, true)) {
			$reason = FormulaVocabulary::isKnown($assigned)
				? "formula $assigned assigned to $identity is not applicable to convention method $conventionMethod"
				: "unknown discovery formula $assigned assigned to $identity";
			$opaques[] = ['reason' => $reason, 'line' => $line];

			return $none;
		}

		$classFile = $entryClass->getFileName();
		if ($classFile === null) {
			return $none;
		}

		$shortName = $entryClass->getNativeReflection()->getShortName();

		if ($assigned === FormulaVocabulary::DIRNAME_TEMPLATES_LCFIRST_FALLBACK) {
			if ($sharedFallback === null) {
				$opaques[] = [
					'reason' => "formula $assigned assigned to $identity requires a sharedFallback",
					'line' => $line,
				];

				return $none;
			}

			if ($resolved['file'] === null) {
				$opaques[] = [
					'reason' => "convention method $conventionMethod declared by $identity has no resolvable file",
					'line' => $line,
				];

				return $none;
			}

			return [
				'path' => FormulaVocabulary::conventionCandidate(
					FormulaVocabulary::DIRNAME_TEMPLATES_LCFIRST,
					$classFile,
					$shortName,
				),
				'fallbackPath' => FormulaVocabulary::conventionFallbackCandidate($resolved['file'], $sharedFallback),
			];
		}

		if ($assigned === FormulaVocabulary::DIRNAME_PROPERTY_LCFIRST) {
			if ($nameProperty === null) {
				$opaques[] = [
					'reason' => "formula $assigned assigned to $identity requires a nameProperty",
					'line' => $line,
				];

				return $none;
			}

			// Resolved off the ENTRY class: the property carries the name, and a subclass that
			// overrides the default while inheriting the convention method names its own template.
			$native = $entryClass->getNativeReflection();
			if (!$native->hasProperty($nameProperty)) {
				$opaques[] = [
					'reason' => "name property $nameProperty of formula $assigned is not declared on "
						. $entryClass->getName(),
					'line' => $line,
				];

				return $none;
			}

			// hasDefaultValue(), never key presence in getDefaultProperties(): PHPStan's reflection
			// is BetterReflection, whose getDefaultProperties() maps EVERY declared property to a
			// value, so a typed property with no default answers null there instead of being absent
			// as it is under native reflection - and null is the value that derives a name.
			$property = $native->getProperty($nameProperty);
			if (!$property->hasDefaultValue()) {
				$opaques[] = [
					'reason' => "name property $nameProperty on " . $entryClass->getName()
						. ' is declared without a default value',
					'line' => $line,
				];

				return $none;
			}

			$name = $property->getDefaultValue();
			if ($name !== null && !is_string($name)) {
				$opaques[] = [
					'reason' => "name property $nameProperty on " . $entryClass->getName()
						. ' has a default that is neither a string nor null',
					'line' => $line,
				];

				return $none;
			}

			return [
				'path' => FormulaVocabulary::conventionPropertyCandidate($classFile, $name),
				'fallbackPath' => null,
			];
		}

		/** @var FormulaVocabulary::DIRNAME_LCFIRST|FormulaVocabulary::DIRNAME_TEMPLATES_LCFIRST $assigned */
		return [
			'path' => FormulaVocabulary::conventionCandidate($assigned, $classFile, $shortName),
			'fallbackPath' => null,
		];
	}

	/**
	 * @param string|array<string, string> $entry
	 * @return array{formula: string, sharedFallback: string|null, nameProperty: string|null}|null
	 */
	private static function normalizedAssignment($entry): ?array
	{
		if (is_string($entry)) {
			return ['formula' => $entry, 'sharedFallback' => null, 'nameProperty' => null];
		}

		if (array_diff_key($entry, ['formula' => null, 'sharedFallback' => null, 'nameProperty' => null]) !== []) {
			return null;
		}

		$formula = $entry['formula'] ?? null;
		if ($formula === null) {
			return null;
		}

		return [
			'formula' => $formula,
			'sharedFallback' => $entry['sharedFallback'] ?? null,
			'nameProperty' => $entry['nameProperty'] ?? null,
		];
	}

	/**
	 * @return array{identity: string, file: string|null}|null
	 */
	private static function declaringIdentity(ClassReflection $entryClass, string $methodName): ?array
	{
		$native = $entryClass->getNativeReflection();
		if (!$native->hasMethod($methodName)) {
			return null;
		}

		$method = $native->getMethod($methodName);
		$declaring = $method->getDeclaringClass();

		$methodFile = $method->getFileName();
		$declaringFile = $declaring->getFileName();
		if ($methodFile !== false && $declaringFile !== false && $methodFile !== $declaringFile) {
			$trait = self::declaringTrait($declaring, $methodName, $methodFile);
			if ($trait !== null) {
				return ['identity' => $trait, 'file' => $methodFile];
			}
		}

		return ['identity' => $declaring->getName(), 'file' => $methodFile === false ? null : $methodFile];
	}

	/**
	 * @param ReflectionClass<object> $class
	 */
	private static function declaringTrait(ReflectionClass $class, string $methodName, string $methodFile): ?string
	{
		foreach ($class->getTraits() as $trait) {
			if ($trait->getFileName() === $methodFile && $trait->hasMethod($methodName)) {
				return $trait->getName();
			}

			$nested = self::declaringTrait($trait, $methodName, $methodFile);
			if ($nested !== null) {
				return $nested;
			}
		}

		return null;
	}

	/**
	 * @param list<string> $paths
	 * @param callable(string): bool $probeFile
	 * @return list<CandidatePath>
	 */
	private function layoutCandidatePaths(array $paths, callable $probeFile): array
	{
		$candidates = [];
		$chosenTaken = false;
		foreach ($paths as $path) {
			$exists = $probeFile($path);
			$chosen = !$chosenTaken && $exists;
			if ($chosen) {
				$chosenTaken = true;
			}

			$candidates[] = new CandidatePath(
				self::relativized($path, $this->projectRootPath),
				$exists,
				$chosen,
				CandidatePath::KIND_LAYOUT,
			);
		}

		return $candidates;
	}

	// Mirror of the deprecated vendor PresenterFactory::unformatPresenterClass(), hardened with a
	// forward round-trip: a class whose inverse name does not format back to the identical class
	// (the vendor inverse is lossy for masks with repeated stars) does not reverse-map at all.
	private function presenterNameFor(string $class): ?string
	{
		$mapping = $this->resolveMapping();
		if ($mapping === null) {
			return null;
		}

		$class = ltrim($class, '\\');

		foreach ($mapping as $moduleKey => $mask) {
			$mask = str_replace(['\\', '*'], ['\\\\', '(\w+)'], $mask);
			if (preg_match("#^\\\\?$mask[0]((?:$mask[1])*)$mask[2]$#Di", $class, $matches) !== 1) {
				continue;
			}

			$name = ($moduleKey === '*' ? '' : $moduleKey . ':')
				. preg_replace("#$mask[1]#iA", '$1:', $matches[1]) . $matches[3];

			if (ltrim(self::formatPresenterClass($name, $mapping), '\\') === $class) {
				return $name;
			}
		}

		return null;
	}

	/**
	 * @param MappingValue $mapping
	 */
	private static function formatPresenterClass(string $name, array $mapping): string
	{
		$parts = explode(':', $name);
		$mask = isset($parts[1], $mapping[$parts[0]])
			? $mapping[array_shift($parts)]
			: ($mapping['*'] ?? ['', '*Module\\', '*Presenter']);

		while (($part = array_shift($parts)) !== null && $part !== '') {
			$mask[0] .= str_replace('*', $part, $mask[$parts === [] ? 2 : 1]);
		}

		return $mask[0];
	}

	/**
	 * @return MappingValue|null
	 */
	private function doResolveMapping(): ?array
	{
		if ($this->containerLoaderFile === null || !is_file($this->containerLoaderFile)) {
			return null;
		}

		try {
			$containers = require $this->containerLoaderFile;
		} catch (Throwable $e) {
			return null;
		}

		if ($containers instanceof Container) {
			$containers = ['default' => $containers];
		}

		if (!is_array($containers)) {
			return null;
		}

		foreach ($containers as $container) {
			if (!$container instanceof Container) {
				continue;
			}

			$mapping = self::mappingFromContainer($container);
			if ($mapping !== null) {
				return $mapping;
			}
		}

		return null;
	}

	/**
	 * @return MappingValue|null
	 */
	private static function mappingFromContainer(Container $container): ?array
	{
		try {
			$factory = $container->getByType(IPresenterFactory::class, false);
		} catch (Throwable $e) {
			return null;
		}

		if (!$factory instanceof PresenterFactory) {
			return null;
		}

		$property = new ReflectionProperty(PresenterFactory::class, 'mapping');
		$property->setAccessible(true);

		return $property->getValue($factory);
	}

	private static function relativized(string $path, string $projectRootPath): string
	{
		$prefix = $projectRootPath . DIRECTORY_SEPARATOR;
		if (strncmp($path, $prefix, strlen($prefix)) === 0) {
			return (string) substr($path, strlen($prefix));
		}

		return $path;
	}

}
