<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version;

// The filters and functions a template of one Latte line can call without any application
// registration, as the installed Latte hands them out, plus the static targets standing in for the
// entries Latte wraps in closures (Latte 2's mbstring/nette-utils guards, Latte 3's inline lambdas).
final class DefaultCallables
{

	/** @var array<string, callable(mixed...): mixed> */
	private array $filters;

	/** @var array<string, callable(mixed...): mixed> */
	private array $functions;

	/** @var array<string, array{string, string}> */
	private array $filterFallbacks;

	/** @var array<string, array{string, string}> */
	private array $functionFallbacks;

	/**
	 * @param array<string, callable(mixed...): mixed> $filters
	 * @param array<string, callable(mixed...): mixed> $functions
	 * @param array<string, array{string, string}> $filterFallbacks
	 * @param array<string, array{string, string}> $functionFallbacks
	 */
	public function __construct(array $filters, array $functions, array $filterFallbacks, array $functionFallbacks)
	{
		$this->filters = $filters;
		$this->functions = $functions;
		$this->filterFallbacks = $filterFallbacks;
		$this->functionFallbacks = $functionFallbacks;
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
	 * @return array{string, string}|null
	 */
	public function filterFallback(string $lowerName): ?array
	{
		return $this->filterFallbacks[$lowerName] ?? null;
	}

	/**
	 * @return array{string, string}|null
	 */
	public function functionFallback(string $lowerName): ?array
	{
		return $this->functionFallbacks[$lowerName] ?? null;
	}

}
