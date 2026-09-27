<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Component;

final class RecursionCtx
{

	public const DEPTH_CAP = 8;

	private int $depth;

	/** @var array<string, true> */
	private array $visited;

	/** @param array<string, true> $visited */
	public function __construct(int $depth = 0, array $visited = [])
	{
		$this->depth = $depth;
		$this->visited = $visited;
	}

	public function exceedsCap(): bool
	{
		return $this->depth >= self::DEPTH_CAP;
	}

	public function alreadyVisited(string $identity): bool
	{
		return isset($this->visited[$identity]);
	}

	public function descend(string $identity): self
	{
		return new self($this->depth + 1, $this->visited + [$identity => true]);
	}

}
