<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

trait LexicalTraitTargetTrait
{

	protected function traitTarget(): void
	{
		$this->template->traitVar = 'from-trait';
	}

}
