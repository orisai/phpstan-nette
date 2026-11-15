<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess;

use OriPhpstan\Nette\Latte\Runtime\Helpers;
use PhpParser\Node\Expr\Closure;
use function strtolower;

// One template's render-time filters, resolved: [class, method, isContentAware, isStatic, stub]. Latte 2
// files a filter under its lowercase name, so two spellings registered with different callables there
// are one filter of no known signature.
final class RenderTimeFilterSet
{

	/** @var array<string, array{string, string, bool, bool, Closure|null}> */
	private array $entries = [];

	/** @var array<string, string> */
	private array $spellings = [];

	private bool $open;

	private bool $caseSensitive;

	/**
	 * @param array<string, array{string, string, bool, bool, Closure|null}> $entries
	 */
	public function __construct(array $entries, bool $open, bool $caseSensitive)
	{
		$this->open = $open;
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
		return new self([], false, false);
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

	public function isOpen(): bool
	{
		return $this->open;
	}

}
