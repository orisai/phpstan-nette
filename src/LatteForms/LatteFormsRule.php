<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\LatteForms;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Forms\Shape\ComponentPath;
use OriPhpstan\Nette\Latte\Forms\ControlReference;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Rule\LatteAnalyzedFileMarkerCollector;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use function array_keys;
use function implode;
use function ksort;
use function sort;
use function sprintf;
use const SORT_STRING;

// The bridge's diagnostics, reported from the merged collected data rather than from each
// template's own parse - LatteTemplateGraphRule's placement and for its reason: PHPStan runs
// CollectedDataNode rules in AnalyserResultFinalizer after the result cache is restored AND saved,
// so the join against the discovery store and the Forms index re-runs in full on every analysis
// while the per-template macro collection stays content-addressed. A rename in a form builder
// therefore reaches its template on a WARM run, with no whole-cache salt and no new invalidation
// edge.
//
// Every verdict here is one-sided by construction. A name is reported only when EVERY linked renderer
// answers ABSENT; a single PRESENT is the legitimate shared-partial pattern, a single UNRESOLVED means
// some renderer's name set is not enumerable, and a renderer whose component did not resolve at all
// leaves the same gap one level up - none of which can be turned into "the name does not exist"
// without inventing the evidence. Silence is the default and every unproven situation falls into it.
//
// Two questions, asked in that order over the same resolved forms: does the named component EXIST
// (the unknown* identifiers), and - once it does - is the macro naming it one that component can
// ANSWER (the mismatch identifiers, whose vendor grounding lives in MacroSuitability). The second
// runs on a weaker gate than the first, for the reason ResolvedForm::identify() spells out.
//
// Deliberately no fixNode(): the check under-detects by construction, so an automated edit would
// delete markup that is correct.

/**
 * @implements Rule<CollectedDataNode>
 */
final class LatteFormsRule implements Rule
{

	public const UNKNOWN_CONTROL_IDENTIFIER = 'orisai.nette.latteForms.unknownControl';

	public const UNKNOWN_FORM_IDENTIFIER = 'orisai.nette.latteForms.unknownForm';

	public const CONTAINER_AS_CONTROL_IDENTIFIER = 'orisai.nette.latteForms.containerAsControl';

	public const CONTROL_AS_CONTAINER_IDENTIFIER = 'orisai.nette.latteForms.controlAsContainer';

	public const LABELLESS_CONTROL_IDENTIFIER = 'orisai.nette.latteForms.labellessControl';

	private const MISMATCH_IDENTIFIERS = [
		MacroSuitability::MISMATCH_CONTAINER_AS_CONTROL => self::CONTAINER_AS_CONTROL_IDENTIFIER,
		MacroSuitability::MISMATCH_CONTROL_AS_CONTAINER => self::CONTROL_AS_CONTAINER_IDENTIFIER,
		MacroSuitability::MISMATCH_NO_LABEL => self::LABELLESS_CONTROL_IDENTIFIER,
	];

	private FormMacroCollector $collector;

	private FormPairing $pairing;

	private MacroSuitability $suitability;

	private LatteUniverse $universe;

	private bool $enabled;

	public function __construct(
		ConfigurationGuard $guard,
		FormMacroCollector $collector,
		FormPairing $pairing,
		MacroSuitability $suitability,
		LatteUniverse $universe
	)
	{
		$guard->validate();
		$this->collector = $collector;
		$this->pairing = $pairing;
		$this->suitability = $suitability;
		$this->universe = $universe;
		$this->enabled = $guard->isBridgeEnabled() && $guard->isLatteDiscoveryEnabled();
	}

	public function getNodeType(): string
	{
		return CollectedDataNode::class;
	}

	/**
	 * @param CollectedDataNode $node
	 * @return list<IdentifierRuleError>
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		// Before any other work: a disabled bridge pays no store read, no template scan and no shape
		// resolution. It needs Forms, Latte and the discovery store for its template->renderer links.
		if (!$this->enabled) {
			return [];
		}

		$this->pairing->bindScope($scope);

		$errors = [];
		foreach ($this->analysedTemplates($node) as $relPath) {
			$file = $this->universe->projectRoot() . '/' . $relPath;

			foreach ($this->collector->sitesFor($relPath) as $site) {
				$formName = $site->getFormName();
				if ($formName === null) {
					continue;
				}

				$notForm = $this->pairing->renderersProvingNoForm($relPath, $formName);
				if ($notForm !== []) {
					// "Is not a form", never "does not exist": a null shape means the analyser could not
					// answer, so the only claim this identifier can prove is about a component it DID
					// resolve, on every linked renderer, to something no form can be.
					$errors[] = RuleErrorBuilder::message(sprintf(
						"Component '%s' is not a form (%s).",
						$formName,
						self::renderers($notForm),
					))
						->identifier(self::UNKNOWN_FORM_IDENTIFIER)
						->file($file)
						->line($site->getLine())
						->build();
				}

				// A linked renderer the pairing could not resolve leaves the site's form set a SUBSET of
				// what may render this template, and a name absent from a subset is absent from nothing.
				// Same discipline as lookup()'s UNRESOLVED answer, one level up: there the analyser could
				// not enumerate a form's names, here it could not reach the form at all.
				$forms = $this->pairing->formsFor($relPath, $formName);
				if ($forms === [] || $this->pairing->hasUnresolvedRenderer($relPath, $formName)) {
					continue;
				}

				foreach ($site->getReferences() as $reference) {
					$name = $reference->getName();

					// A dynamic macro argument names nothing, and an existence-checked reference is
					// the author's own declaration that presence is conditional (offsetExists throws
					// for nobody) - the same exemption the Forms extension's own does-not-exist rule
					// makes for isset() in PHP.
					if ($name === null || $reference->isGuarded()) {
						continue;
					}

					$containerPath = $reference->getContainerPath();

					$absentFrom = $this->absentFrom($forms, $containerPath, $name);
					if ($absentFrom !== []) {
						$errors[] = RuleErrorBuilder::message(sprintf(
							"Control '%s' does not exist on form '%s' (%s).",
							self::dottedPath($containerPath, $name),
							$formName,
							self::renderers($absentFrom),
						))
							->identifier(self::UNKNOWN_CONTROL_IDENTIFIER)
							->file($file)
							->line($reference->getLine())
							->build();

						continue;
					}

					// The name exists; the remaining question is whether the macro naming it is one
					// that component can answer. Absence and mismatch are mutually exclusive by
					// construction - a name reported absent resolves to no component on any form -
					// so the two never double-report the same line.
					$mismatch = $this->mismatchAcross($forms, $reference, $name);
					if ($mismatch === null) {
						continue;
					}

					$errors[] = RuleErrorBuilder::message(self::mismatchMessage(
						$mismatch,
						self::dottedPath($containerPath, $name),
						$formName,
					))
						->identifier(self::MISMATCH_IDENTIFIERS[$mismatch['kind']])
						->file($file)
						->line($reference->getLine())
						->build();
				}
			}
		}

		return $errors;
	}

	// The multi-renderer join. Empty means silent, and the two ways of getting there are kept apart
	// on purpose: PRESENT anywhere is the shared-partial pattern (design §2's owner decision), while
	// UNRESOLVED anywhere is the certainty gate refusing to answer - a renderer whose name set the
	// analyser could not enumerate may well declare this very name.

	/**
	 * @param list<ResolvedForm> $forms
	 * @param list<string> $containerPath
	 * @return list<string>
	 */
	private function absentFrom(array $forms, array $containerPath, string $name): array
	{
		$renderers = [];
		foreach ($forms as $form) {
			$verdict = $form->lookup($containerPath, $name);

			if ($verdict === ResolvedForm::LOOKUP_PRESENT) {
				return [];
			}

			if ($verdict === ResolvedForm::LOOKUP_UNRESOLVED) {
				return [];
			}

			$renderers[] = $form->getRendererClass();
		}

		return $renderers;
	}

	// The same multi-renderer join one question further in, and it declines for one more reason than
	// absence does: a mismatch must be the SAME mismatch on every linked renderer's form. A name that
	// is a container on one and a control on another is a shared partial whose two paths disagree,
	// which is the analysis being unable to tell a bug from a legitimate pattern - exactly what a
	// single PRESENT means for absence.

	/**
	 * @param list<ResolvedForm> $forms
	 * @return array{kind: MacroSuitability::MISMATCH_*, classes: list<string>, renderers: list<string>}|null
	 */
	private function mismatchAcross(array $forms, ControlReference $reference, string $name): ?array
	{
		$kind = null;
		$classes = [];
		$renderers = [];

		foreach ($forms as $form) {
			$identity = $form->identify($reference->getContainerPath(), $name);
			if ($identity === null) {
				return null;
			}

			$verdict = $this->suitability->mismatch($reference->getKind(), $identity);
			if ($verdict === null || ($kind !== null && $kind !== $verdict)) {
				return null;
			}

			$kind = $verdict;
			foreach ($identity->getClasses() ?? [] as $class) {
				$classes[$class] = true;
			}

			$renderers[] = $form->getRendererClass();
		}

		if ($kind === null) {
			return null;
		}

		return ['kind' => $kind, 'classes' => array_keys($classes), 'renderers' => $renderers];
	}

	/**
	 * @param array{kind: MacroSuitability::MISMATCH_*, classes: list<string>, renderers: list<string>} $mismatch
	 */
	private static function mismatchMessage(array $mismatch, string $path, string $formName): string
	{
		$renderers = self::renderers($mismatch['renderers']);

		if ($mismatch['kind'] === MacroSuitability::MISMATCH_CONTAINER_AS_CONTROL) {
			return sprintf(
				"Component '%s' on form '%s' is a container, not a control (%s).",
				$path,
				$formName,
				$renderers,
			);
		}

		if ($mismatch['kind'] === MacroSuitability::MISMATCH_CONTROL_AS_CONTAINER) {
			return sprintf(
				"Component '%s' on form '%s' is a control, not a container (%s).",
				$path,
				$formName,
				$renderers,
			);
		}

		return sprintf(
			"Control '%s' on form '%s' is a %s, which renders no label (%s).",
			$path,
			$formName,
			self::renderers($mismatch['classes'], '|'),
			$renderers,
		);
	}

	// The reportable set is the ANALYSED template set, never the whole .latte universe and never the
	// store's link set: a template nobody asked PHPStan to analyse gains no finding. Sorted here
	// because collected data arrives in whatever order the workers finished in.

	/**
	 * @return list<string>
	 */
	private function analysedTemplates(CollectedDataNode $node): array
	{
		$templates = [];
		foreach ($node->get(LatteAnalyzedFileMarkerCollector::class) as $perFile) {
			foreach ($perFile as $relPath) {
				$templates[$relPath] = true;
			}
		}

		ksort($templates, SORT_STRING);

		return array_keys($templates);
	}

	// The two sources of nesting compose into the one path Nette resolves at runtime: the lexical
	// {formContainer} chain, then the '-' segments Container::getComponent() explodes itself.

	/**
	 * @param list<string> $containerPath
	 */
	private static function dottedPath(array $containerPath, string $name): string
	{
		return implode('.', [
			...$containerPath,
			...ComponentPath::split($name),
		]);
	}

	/**
	 * @param list<string> $classes
	 */
	private static function renderers(array $classes, string $glue = ', '): string
	{
		sort($classes, SORT_STRING);

		return implode($glue, $classes);
	}

}
