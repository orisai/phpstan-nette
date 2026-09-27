<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Analyzer;

final class ReturnPointCollector
{

	/** @var list<CompositionState> */
	private array $states = [];

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
