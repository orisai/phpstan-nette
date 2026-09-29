<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Compile;

use InvalidArgumentException;
use Latte\CompileException;
use Latte\Compiler;
use Latte\Macro;
use Latte\Macros\BlockMacros;
use Latte\Macros\CoreMacros;
use Latte\Parser;
use Latte\Runtime\Defaults;
use LogicException;
use Nette\Bridges\ApplicationLatte\UIMacros;
use Nette\Bridges\CacheLatte\CacheMacro;
use Nette\Bridges\FormsLatte\FormMacros;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use OriPhpstan\Nette\Latte\Cache\LatteAnalysisCache;
use OriPhpstan\Nette\Latte\Customs\CustomsHarvester;
use OriPhpstan\Nette\Latte\Customs\HarvestedCustoms;
use OriPhpstan\Nette\Latte\Postprocess\CaseMismatchScanner;
use Throwable;
use function array_keys;
use function array_merge;
use function get_class;
use function implode;
use function in_array;
use function is_callable;
use function preg_match;
use function sha1;
use const E_USER_DEPRECATED;

final class LatteCompiler
{

	private const MAX_UNKNOWN_MACRO_RETRIES = 20;

	private const CACHE_NODE_ID = 'latte-compile';

	// A harvested macro set re-registering one of these exact classes must never be reinstalled:
	// CoreMacros/BlockMacros/UIMacros/FormMacros are already installed faithfully below, and the
	// vendor CacheMacro is the nondeterministic (Random::generate()) class DeterministicCacheMacro
	// deliberately replaces under the 'cache' name - reinstalling it would shadow that replacement
	// via Latte's own last-registered-wins macro dispatch (Compiler::expandMacro()).
	private const PROTECTED_MACRO_SET_CLASSES = [
		CoreMacros::class,
		BlockMacros::class,
		UIMacros::class,
		FormMacros::class,
		CacheMacro::class,
	];

	private ?LatteAnalysisCache $cache;

	private ?CustomsHarvester $harvester;

	private ?DiscoveryStore $discoveryStore;

	private bool $discoveryStoreEnabled;

	private ?CaseMismatchScanner $caseMismatchScanner = null;

	public function __construct(
		?LatteAnalysisCache $cache = null,
		?CustomsHarvester $harvester = null,
		?DiscoveryStore $discoveryStore = null,
		bool $discoveryStoreEnabled = false
	)
	{
		$this->cache = $cache;
		$this->harvester = $harvester;
		$this->discoveryStore = $discoveryStore;
		$this->discoveryStoreEnabled = $discoveryStoreEnabled;
	}

	private function caseMismatchScanner(): CaseMismatchScanner
	{
		if ($this->caseMismatchScanner === null) {
			$this->caseMismatchScanner = new CaseMismatchScanner(
				$this->harvester !== null ? $this->harvester->harvest() : null,
			);
		}

		return $this->caseMismatchScanner;
	}

	public function compile(string $latteSource, string $className, string $cacheIdentity = ''): CompileResult
	{
		if ($this->cache === null) {
			return $this->doCompile($latteSource, $className);
		}

		// Folds the harvest salt in: a cached CompileResult bakes in harvest-dependent shapes (native
		// macros vs passthrough, unknownMacro, caseMismatch), so a harvest change must miss here the
		// same way it already invalidates PHPStan's own result cache (LatteResultCacheMeta).
		$harvestSalt = ($this->harvester !== null ? $this->harvester->harvest() : HarvestedCustoms::empty())
			->getSaltHash();
		// The discovery-store record salt follows the same two-cache discipline: store consumers bake
		// record-derived shapes into cached analysis artifacts, so a record change must miss here
		// rather than serve a pre-change result. Per-template, keyed by $className: this cache
		// persists across runs, so a store-wide hash would evict every entry on any single-record
		// change. Disabled short-circuits to a constant salt before any store read (SiteScopeStore
		// discipline).
		$discoverySalt = $this->discoveryStoreEnabled && $this->discoveryStore !== null
			? $this->discoveryStore->recordsSaltForTemplateClass($className)
			: 'disabled';
		$contentHash = implode('|', [sha1($latteSource), $className, $harvestSalt, $discoverySalt, $cacheIdentity]);

		/** @var array{result: CompileResult}|null $cached */
		$cached = $this->cache->readContentAddressed($contentHash, self::CACHE_NODE_ID);
		if ($cached !== null) {
			return $cached['result'];
		}

		$result = $this->doCompile($latteSource, $className);

		// Only a successful compile is cacheable content: DiagnosticMaterializer already gives a
		// failed compile's minimal stand-in class no analysis to protect, so there is nothing worth
		// short-circuiting, and it keeps this cache's on-disk contents free of parse-error outcomes.
		if ($result->getPhpSource() !== null) {
			$this->cache->writeContentAddressed($contentHash, self::CACHE_NODE_ID, ['result' => $result]);
		}

		return $result;
	}

	private function doCompile(string $latteSource, string $className): CompileResult
	{
		$parser = new Parser();

		try {
			$tokens = $parser->parse($latteSource);
		} catch (CompileException | InvalidArgumentException $e) {
			return CompileResult::failure(
				$className,
				new Diagnostic(
					'orisaiNette.latte.parseError',
					$e->getMessage(),
					$this->recoverLine($parser->getLine()),
				),
			);
		}

		$diagnostics = [];
		$passthroughNames = [];
		$line = 1;

		for ($attempt = 0; $attempt <= self::MAX_UNKNOWN_MACRO_RETRIES; $attempt++) {
			try {
				$compiler = $this->createCompiler($passthroughNames);
			} catch (LogicException $e) {
				// addMacro() validates the name against a stricter charset than matchUnknownName's
				// extraction regex allows (e.g. a leading digit) - a mismatch throws here instead of
				// producing another retry-worthy CompileException. $passthroughNames is only ever
				// non-empty from attempt 1 onward, so the CompileException catch below has always
				// already set $line for this same name on the prior attempt.
				return CompileResult::failure(
					$className,
					new Diagnostic('orisaiNette.latte.parseError', $e->getMessage(), $line),
				);
			}

			$deprecations = [];

			try {
				$phpSource = VendorErrorContainment::run(
					static fn (): string => $compiler->compile($tokens, $className, null, false),
					// Every other severity vendor Latte can trigger_error() - including the
					// filter/function case-mismatch warnings PhpWriter fires for the stock 7
					// Defaults functions and the checkUrl filter, which CaseMismatchScanner below
					// independently detects with full custom-name coverage - is vendor-internal
					// noise: contained here, never reported.
					function (int $severity, string $message) use ($compiler, &$deprecations): void {
						if ($severity === E_USER_DEPRECATED) {
							$deprecations[] = new Diagnostic(
								'orisaiNette.latte.deprecated',
								$message,
								$this->recoverLine($compiler->getLine()),
							);
						}
					},
				);

				$caseMismatchDiagnostics = $this->caseMismatchScanner()->scan($tokens);

				return CompileResult::success(
					$phpSource,
					$className,
					array_merge($diagnostics, $deprecations, $caseMismatchDiagnostics),
				);
			} catch (CompileException $e) {
				$line = $this->recoverLine($compiler->getLine());
				$unknownName = $this->matchUnknownName($e->getMessage());

				if ($unknownName === null) {
					return CompileResult::failure(
						$className,
						new Diagnostic('orisaiNette.latte.parseError', $e->getMessage(), $line),
					);
				}

				$diagnostics[] = new Diagnostic(
					'orisaiNette.latte.unknownMacro',
					"Unknown Latte macro or attribute '$unknownName'.",
					$line,
				);
				$passthroughNames[$unknownName] = true;
			} catch (InvalidArgumentException $e) {
				return CompileResult::failure(
					$className,
					new Diagnostic(
						'orisaiNette.latte.parseError',
						$e->getMessage(),
						$this->recoverLine($compiler->getLine()),
					),
				);
			}
		}

		return CompileResult::failure(
			$className,
			new Diagnostic('orisaiNette.latte.parseError', 'Too many unknown macros.', 1),
		);
	}

	/**
	 * @param array<string, bool> $passthroughNames
	 */
	private function createCompiler(array $passthroughNames): Compiler
	{
		$compiler = new Compiler();
		CoreMacros::install($compiler);
		BlockMacros::install($compiler);
		UIMacros::install($compiler);
		FormMacros::install($compiler);
		$compiler->addMacro('cache', new DeterministicCacheMacro());
		$compiler->setFunctions(array_keys((new Defaults())->getFunctions()));

		$this->installHarvestedMacroSets($compiler);

		foreach (array_keys($passthroughNames) as $name) {
			$compiler->addMacro($name, new PassthroughMacro(), Macro::AUTO_CLOSE);
		}

		return $compiler;
	}

	// Reinstalling each harvested set fresh (SomeClass::install($compiler)) - rather than reusing
	// the harvested instance itself - keeps every macro bound to THIS compiler: MacroSet::compile()
	// builds its PhpWriter from the compiler it was constructed with, so a reused instance would
	// carry the harvest-time compiler's (unrelated) filters/functions/policy into every compile.
	// Installed strictly after the five built-ins above, matching production's own registration
	// order (Engine::getCompiler() installs Core/Block eagerly; every onCompile-registered set,
	// including the app's gettext family, runs after) - a harvested set reclaiming a built-in NAME
	// (e.g. gettext's own '_') wins the same way it already does at runtime, via Latte's
	// last-registered-wins macro dispatch.
	private function installHarvestedMacroSets(Compiler $compiler): void
	{
		$harvester = $this->harvester;

		if ($harvester === null) {
			return;
		}

		// The five built-ins above stay outside VendorErrorContainment (pre-existing convention -
		// their install() calls are vetted first-party/framework code, same as production's own
		// unwrapped TemplateFactory wiring). A harvested set's install() is genuinely third-party
		// and previously ran fully unguarded; wrapped here so a stray trigger_error() (e.g. a
		// deprecation notice fired during registration, not compilation) is contained instead of
		// leaking to stdout, matching the compile()-call wrap below and CustomsHarvester's own
		// harvest-time wrap. No per-template line/diagnostic sink exists at this stage
		// (createCompiler() runs once per compile ATTEMPT, before any node is being compiled), so
		// every severity is dropped silently here too - the same policy
		// CustomsHarvester::doHarvest() already applies to its own vendor invocations.
		VendorErrorContainment::run(
			static function () use ($compiler, $harvester): void {
				$installedClasses = [];
				foreach ($harvester->harvest()->getMacroSets() as $macroSet) {
					$class = get_class($macroSet);

					if (isset($installedClasses[$class]) || in_array($class, self::PROTECTED_MACRO_SET_CLASSES, true)) {
						continue;
					}

					$installedClasses[$class] = true;

					if (!is_callable([$class, 'install'])) {
						continue;
					}

					try {
						$class::install($compiler);
					} catch (Throwable $e) {
						continue;
					}
				}
			},
			static function (int $severity, string $message): void {
			},
		);
	}

	private function matchUnknownName(string $message): ?string
	{
		if (preg_match('~Unknown (?:macro|tag) \{(?:/)?([\w:.-]+)~', $message, $m) === 1) {
			return $m[1];
		}

		if (preg_match('~Unknown attribute n:(?:inner-|tag-)?([\w:.-]+)~', $message, $m) === 1) {
			return $m[1];
		}

		return null;
	}

	private function recoverLine(?int $line): int
	{
		return $line !== null && $line > 0 ? $line : 1;
	}

}
