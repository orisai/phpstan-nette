<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

trait CycleHelperTrait
{

	private function traitStep(): void
	{
		$this->hostStep();
	}

}
