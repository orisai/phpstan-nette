<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Analyzer;

final class ReturnPointCollector
{

	/** @var list<CompositionState> */
	private array $states = [];

	private bool $everyReturnExits;

	// A walked parameter outlives the method, so any return hands the caller the form as it stands;
	// a walked local reaches the caller only through `return $local`.
	public function __construct(bool $everyReturnExits = false)
	{
		$this->everyReturnExits = $everyReturnExits;
	}

	public function isEveryReturnAnExit(): bool
	{
		return $this->everyReturnExits;
	}

	public function record(CompositionState $state): void
	{
		$this->states[] = $state;
	}

	/** @return list<CompositionState> */
	public function getStates(): array
	{
		return $this->states;
	}

}
