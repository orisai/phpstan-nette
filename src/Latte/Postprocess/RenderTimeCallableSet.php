<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess;

use Closure as BaseClosure;
use OriPhpstan\Nette\Latte\Includes\RenderTimeCustoms;
use OriPhpstan\Nette\Latte\Runtime\Helpers;
use PhpParser\Node\Expr\Closure;
use function strtolower;
use function usort;

// One template's render-time filters or functions, resolved: [class, method, isContentAware, isStatic,
// stub]. Latte 2 files them under their lowercase name, so two spellings registered with different
// callables there are one name of no known signature.
/**
 * @phpstan-import-type OpenSite from RenderTimeCustoms
 */
final class RenderTimeCallableSet
{

	/** @var array<string, array{string, string, bool, bool, Closure|null}> */
	private array $entries = [];

	/** @var array<string, string> */
	private array $spellings = [];

	/** @var list<OpenSite> */
	private array $openSites;

	/** @var (BaseClosure(string): bool)|null */
	private ?BaseClosure $functionExists;

	private bool $caseSensitive;

	/**
	 * @param array<string, array{string, string, bool, bool, Closure|null}> $entries
	 * @param list<OpenSite> $openSites
	 * @param (BaseClosure(string): bool)|null $functionExists
	 */
	public function __construct(
		array $entries,
		array $openSites,
		bool $caseSensitive,
		?BaseClosure $functionExists = null
	)
	{
		$this->openSites = $openSites;
		$this->functionExists = $functionExists;
		$this->caseSensitive = $caseSensitive;
		foreach ($entries as $name => $entry) {
			$key = $caseSensitive ? $name : strtolower($name);
			$this->entries[$key] = isset($this->entries[$key]) && $this->entries[$key] !== $entry
				? [Helpers::class, 'untypedFilter', false, true, null]
				: $entry;
			$this->spellings[strtolower($name)] = $name;
		}
	}

	public static function none(): self
	{
		return new self([], [], false);
	}

	/**
	 * @return array{string, string, bool, bool, Closure|null}|null
	 */
	public function resolve(string $writtenName): ?array
	{
		return $this->entries[$this->caseSensitive ? $writtenName : strtolower($writtenName)] ?? null;
	}

	public function registeredSpelling(string $writtenName): ?string
	{
		if (!$this->caseSensitive || isset($this->entries[$writtenName])) {
			return null;
		}

		return $this->spellings[strtolower($writtenName)] ?? null;
	}

	// The first site opening the set, in a stable order, for the possibly-unknown finding to name.

	/**
	 * @return OpenSite|null
	 */
	public function firstOpenSite(): ?array
	{
		$sites = $this->openSites;
		usort(
			$sites,
			static fn (array $a, array $b): int => [$a['class'], $a['file'], $a['line']] <=> [$b['class'], $b['file'], $b['line']],
		);

		return $sites[0] ?? null;
	}

	public function hasFunction(string $name): bool
	{
		return $this->functionExists !== null && ($this->functionExists)($name);
	}

}
