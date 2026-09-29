<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Customs;

use Latte\Engine;
use Latte\Macro;
use Latte\Runtime\FilterExecutor;
use OriPhpstan\Nette\Latte\Compile\VendorErrorContainment;
use ReflectionProperty;
use stdClass;
use Throwable;
use function array_keys;
use function assert;
use function end;
use function get_class;
use function get_object_vars;
use function spl_object_id;
use function strtolower;

final class CustomsHarvester
{

	private EngineSource $engineSource;

	private ?HarvestedCustoms $harvested = null;

	public function __construct(EngineSource $engineSource)
	{
		$this->engineSource = $engineSource;
	}

	public function harvest(): HarvestedCustoms
	{
		if ($this->harvested === null) {
			$this->harvested = $this->doHarvest();
		}

		return $this->harvested;
	}

	public function hasConfiguredSource(): bool
	{
		return $this->engineSource->isConfigured();
	}

	private function doHarvest(): HarvestedCustoms
	{
		try {
			return VendorErrorContainment::run(
				function (): HarvestedCustoms {
					$engine = $this->engineSource->resolve();

					if ($engine === null) {
						return HarvestedCustoms::empty();
					}

					return self::enumerate($engine);
				},
				// No per-template line exists here - harvest runs once per analysis, not once per
				// compiled template - so every severity is contained and dropped, same
				// vendor-internal-noise policy LatteCompiler applies to its own non-deprecation
				// captures. Unlike LatteCompiler's wrap, this window also covers our own resolve()/
				// enumerate() code, not just vendor calls - an accepted trade (spec explicitly scopes
				// the wrap to "engine creation, enumeration, onCompile"), consistent with harvest's
				// existing silent-degradation contract: an own-code notice in here is contained the
				// same as a vendor one, never surfaced.
				static function (int $severity, string $message): void {
				},
			);
		} catch (Throwable $e) {
			return HarvestedCustoms::empty();
		}
	}

	public static function enumerate(Engine $engine): HarvestedCustoms
	{
		[$filters, $filterOriginalNames] = self::readFilters($engine);
		[$functions, $functionOriginalNames] = self::readFunctions($engine);

		// addFilterLoader() dynamic loaders (name === null, resolved lazily per unresolved name)
		// are opaque to Engine::getFilters() and unused by this app - not modeled here.
		foreach ($engine->onCompile as $callback) {
			$callback($engine);
		}

		$macrosByName = $engine->getCompiler()->getMacros();

		return new HarvestedCustoms(
			$filters,
			$functions,
			self::macroSets($macrosByName),
			self::macroClassesByName($macrosByName),
			$filterOriginalNames,
			$functionOriginalNames,
		);
	}

	// Latte's own Compiler::expandMacro() tries $this->macros[$name] in array_reverse() order and
	// dispatches to the first one whose nodeOpened() accepts - the LAST-registered entry, matching
	// LatteCompiler::installHarvestedMacroSets()'s own "last-registered-wins" precedent.

	/**
	 * @param array<string, array<Macro>> $macrosByName
	 * @return array<string, class-string>
	 */
	private static function macroClassesByName(array $macrosByName): array
	{
		$classesByName = [];
		foreach ($macrosByName as $name => $macros) {
			$winner = end($macros);
			if ($winner === false) {
				continue;
			}

			$classesByName[$name] = get_class($winner);
		}

		return $classesByName;
	}

	/**
	 * @return array{array<string, callable(mixed...): mixed>, array<string, string>}
	 */
	private static function readFilters(Engine $engine): array
	{
		// Engine::getFilters() (@return string[]) returns lowercase-name => same-name pairs, not
		// callables - FilterExecutor::__get() (public, magic) is the only way to resolve a real,
		// invokable callback per name, exactly what Engine::invokeFilter() itself does at runtime.
		$names = $engine->getFilters();

		$property = new ReflectionProperty(Engine::class, 'filters');
		$property->setAccessible(true);

		$executor = $property->getValue($engine);
		assert($executor instanceof FilterExecutor);

		$filters = [];
		foreach (array_keys($names) as $name) {
			$filters[$name] = $executor->$name;
		}

		// $_origNames is [registeredSpelling => lowercase], one entry per distinct spelling ever
		// passed to FilterExecutor::add() - see OriginalNameCollisionMap's own doc for why this is
		// collision-resolved rather than a naive single flip.
		return [$filters, OriginalNameCollisionMap::build($executor->_origNames)];
	}

	/**
	 * @return array{array<string, callable(mixed...): mixed>, array<string, string>}
	 */
	private static function readFunctions(Engine $engine): array
	{
		$property = new ReflectionProperty(Engine::class, 'functions');
		$property->setAccessible(true);

		$functions = $property->getValue($engine);
		assert($functions instanceof stdClass);

		/** @var array<string, callable(mixed...): mixed> $vars */
		$vars = get_object_vars($functions);

		// Engine::addFunction() keeps the exact spelling passed in as the stdClass property name
		// (no separate lowercase tracking like FilterExecutor) - the property names ARE the
		// registered spellings.
		$origToLower = [];
		foreach (array_keys($vars) as $name) {
			$origToLower[$name] = strtolower($name);
		}

		return [$vars, OriginalNameCollisionMap::build($origToLower)];
	}

	/**
	 * @param array<array<Macro>> $macrosByName
	 * @return list<object>
	 */
	private static function macroSets(array $macrosByName): array
	{
		$seen = [];
		$sets = [];

		foreach ($macrosByName as $macros) {
			foreach ($macros as $macro) {
				$id = spl_object_id($macro);

				if (isset($seen[$id])) {
					continue;
				}

				$seen[$id] = true;
				$sets[] = $macro;
			}
		}

		return $sets;
	}

}
