<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Customs;

use Closure;
use Latte\Macros\MacroSet;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use function array_keys;
use function get_class;
use function implode;
use function is_array;
use function is_object;
use function is_string;
use function ksort;
use function sha1;
use function sha1_file;
use const SORT_STRING;

final class HarvestedCustoms
{

	/** @var array<string, callable(mixed...): mixed> */
	private array $filters;

	/** @var array<string, callable(mixed...): mixed> */
	private array $functions;

	// Binding shape: a harvested macro provider is usually a MacroSet (the
	// documented, common case - every vendor macro install() creates one) but Latte's Macro
	// interface allows any object, so the wider `object` arm stays even though it makes the union
	// non-normalized.
	/** @var array<int, MacroSet|object> */
	private array $macroSets;

	/** @var array<string, class-string> */
	private array $macroClassesByName;

	/** @var array<string, string> */
	private array $filterOriginalNames;

	/** @var array<string, string> */
	private array $functionOriginalNames;

	// Latte 3 only: the engine's extensions in registration order, its feature flags as the engine
	// stores them (3.0 keys by the Feature constant's value, 3.1 by the enum case name) and its
	// providers' value types. Empty for a Latte 2 harvest, which keeps its salt unchanged.
	/** @var list<object> */
	private array $extensions;

	/** @var array<string, bool> */
	private array $features;

	/** @var array<string, string> */
	private array $providerTypes;

	private string $saltHash;

	/**
	 * @param array<string, callable(mixed...): mixed> $filters
	 * @param array<string, callable(mixed...): mixed> $functions
	 * @param array<int, MacroSet|object> $macroSets
	 * @param array<string, class-string> $macroClassesByName
	 * @param array<string, string> $filterOriginalNames
	 * @param array<string, string> $functionOriginalNames
	 * @param list<object> $extensions
	 * @param array<string, bool> $features
	 * @param array<string, string> $providerTypes
	 */
	public function __construct(
		array $filters,
		array $functions,
		array $macroSets,
		array $macroClassesByName,
		array $filterOriginalNames,
		array $functionOriginalNames,
		array $extensions = [],
		array $features = [],
		array $providerTypes = []
	)
	{
		$this->filters = $filters;
		$this->functions = $functions;
		$this->macroSets = $macroSets;
		$this->macroClassesByName = $macroClassesByName;
		$this->filterOriginalNames = $filterOriginalNames;
		$this->functionOriginalNames = $functionOriginalNames;
		$this->extensions = $extensions;
		$this->features = $features;
		$this->providerTypes = $providerTypes;
		$this->saltHash = self::computeSaltHash(
			$filters,
			$functions,
			$macroClassesByName,
			$filterOriginalNames,
			$functionOriginalNames,
			$extensions,
			$features,
			$providerTypes,
		);
	}

	public static function empty(): self
	{
		return new self([], [], [], [], [], []);
	}

	// A Latte 3 engine's tags live on its extensions: $tagClassesByName maps every name the parser
	// accepts - including the n:, n:inner- and n:tag- attributes Latte derives from a generator
	// tag parser - to the extension class that wins it.

	/**
	 * @param array<string, callable(mixed...): mixed> $filters
	 * @param array<string, callable(mixed...): mixed> $functions
	 * @param list<object> $extensions
	 * @param array<string, class-string> $tagClassesByName
	 * @param array<string, string> $filterOriginalNames
	 * @param array<string, string> $functionOriginalNames
	 * @param array<string, bool> $features
	 * @param array<string, string> $providerTypes
	 */
	public static function fromExtensions(
		array $filters,
		array $functions,
		array $extensions,
		array $tagClassesByName,
		array $filterOriginalNames,
		array $functionOriginalNames,
		array $features,
		array $providerTypes
	): self
	{
		return new self(
			$filters,
			$functions,
			[],
			$tagClassesByName,
			$filterOriginalNames,
			$functionOriginalNames,
			$extensions,
			$features,
			$providerTypes,
		);
	}

	public function isExtensionHarvest(): bool
	{
		return $this->extensions !== [];
	}

	/**
	 * @return list<object>
	 */
	public function getExtensions(): array
	{
		return $this->extensions;
	}

	/**
	 * @return array<string, bool>
	 */
	public function getFeatures(): array
	{
		return $this->features;
	}

	/**
	 * @return array<string, string>
	 */
	public function getProviderTypes(): array
	{
		return $this->providerTypes;
	}

	/**
	 * @return array<string, callable(mixed...): mixed>
	 */
	public function getFilters(): array
	{
		return $this->filters;
	}

	/**
	 * @return array<string, callable(mixed...): mixed>
	 */
	public function getFunctions(): array
	{
		return $this->functions;
	}

	/**
	 * @return array<int, MacroSet|object>
	 */
	public function getMacroSets(): array
	{
		return $this->macroSets;
	}

	/**
	 * @return list<string>
	 */
	public function getMacroNames(): array
	{
		return array_keys($this->macroClassesByName);
	}

	/**
	 * @return array<string, string>
	 */
	public function getFilterOriginalNames(): array
	{
		return $this->filterOriginalNames;
	}

	/**
	 * @return array<string, string>
	 */
	public function getFunctionOriginalNames(): array
	{
		return $this->functionOriginalNames;
	}

	public function getSaltHash(): string
	{
		return $this->saltHash;
	}

	/**
	 * @param array<string, callable(mixed...): mixed> $filters
	 * @param array<string, callable(mixed...): mixed> $functions
	 * @param array<string, class-string> $macroClassesByName
	 * @param array<string, string> $filterOriginalNames
	 * @param array<string, string> $functionOriginalNames
	 * @param list<object> $extensions
	 * @param array<string, bool> $features
	 * @param array<string, string> $providerTypes
	 */
	private static function computeSaltHash(
		array $filters,
		array $functions,
		array $macroClassesByName,
		array $filterOriginalNames,
		array $functionOriginalNames,
		array $extensions,
		array $features,
		array $providerTypes
	): string
	{
		$lines = self::canonicalCallableLines('filter', $filters);

		foreach (self::canonicalCallableLines('function', $functions) as $line) {
			$lines[] = $line;
		}

		foreach (self::canonicalMacroLines($macroClassesByName) as $line) {
			$lines[] = $line;
		}

		foreach (self::canonicalOriginalNameLines('filterOrig', $filterOriginalNames) as $line) {
			$lines[] = $line;
		}

		foreach (self::canonicalOriginalNameLines('functionOrig', $functionOriginalNames) as $line) {
			$lines[] = $line;
		}

		foreach ($extensions as $index => $extension) {
			$class = get_class($extension);
			$lines[] = "extension\x1f" . $index . "\x1f" . $class . "\x1f" . self::describeMacroClass($class);
		}

		ksort($features, SORT_STRING);
		foreach ($features as $feature => $enabled) {
			$lines[] = "feature\x1f" . $feature . "\x1f" . ($enabled ? '1' : '0');
		}

		foreach (self::canonicalOriginalNameLines('provider', $providerTypes) as $line) {
			$lines[] = $line;
		}

		return sha1(implode("\n", $lines));
	}

	// Name alone is too weak: macro-provider packages sit outside LatteCodeVersion's tracked
	// composer slice, so a same-name macro CODE change (a class swap, an edited install()/
	// nodeOpened() body) must show up here instead - class identity plus a content hash of its
	// declaring file, mirroring describeCallable()'s closure descriptor. One file read per unique
	// class: several tag names commonly share one MacroSet.

	/**
	 * @param array<string, class-string> $macroClassesByName
	 * @return list<string>
	 */
	private static function canonicalMacroLines(array $macroClassesByName): array
	{
		ksort($macroClassesByName, SORT_STRING);

		$fileHashes = [];
		$lines = [];
		foreach ($macroClassesByName as $name => $class) {
			if (!isset($fileHashes[$class])) {
				$fileHashes[$class] = self::describeMacroClass($class);
			}

			$lines[] = "macro\x1f" . $name . "\x1f" . $class . "\x1f" . $fileHashes[$class];
		}

		return $lines;
	}

	/**
	 * @param class-string $class
	 */
	private static function describeMacroClass(string $class): string
	{
		try {
			$fileName = (new ReflectionClass($class))->getFileName();
		} catch (ReflectionException $e) {
			return 'unreflectable';
		}

		if ($fileName === false) {
			return 'internal';
		}

		$hash = sha1_file($fileName);

		return $hash !== false ? $hash : 'unreadable';
	}

	/**
	 * @param array<string, string> $originalNames
	 * @return list<string>
	 */
	private static function canonicalOriginalNameLines(string $kind, array $originalNames): array
	{
		ksort($originalNames, SORT_STRING);

		$lines = [];
		foreach ($originalNames as $lower => $orig) {
			$lines[] = $kind . "\x1f" . $lower . "\x1f" . $orig;
		}

		return $lines;
	}

	/**
	 * @param array<string, callable(mixed...): mixed> $callables
	 * @return list<string>
	 */
	private static function canonicalCallableLines(string $kind, array $callables): array
	{
		ksort($callables, SORT_STRING);

		$lines = [];
		foreach ($callables as $name => $callable) {
			$lines[] = $kind . "\x1f" . $name . "\x1f" . self::describeCallable($callable);
		}

		return $lines;
	}

	/**
	 * @param callable(mixed...): mixed $callable
	 */
	private static function describeCallable(callable $callable): string
	{
		if (is_array($callable)) {
			[$objectOrClass, $method] = $callable;

			return (is_object($objectOrClass) ? get_class($objectOrClass) : $objectOrClass) . '::' . $method;
		}

		if (is_string($callable)) {
			return $callable;
		}

		if ($callable instanceof Closure) {
			// File+line is the canonical identity: it's a stable descriptor across two harvests of
			// the same source, and a body-only edit still shows up through this same closure's file
			// changing - which the harvest's other invalidation inputs (container file hash,
			// composer.lock) already cover independently of this salt.
			$reflection = new ReflectionFunction($callable);
			$fileName = $reflection->getFileName();

			return 'closure:' . ($fileName !== false ? $fileName : '?') . ':' . $reflection->getStartLine() . '-' . $reflection->getEndLine();
		}

		if (is_object($callable)) {
			return get_class($callable) . '::__invoke';
		}

		return 'unknown';
	}

}
