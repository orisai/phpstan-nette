<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

final class LexicalCallEntryPresenter extends LexicalCallBaseFixture
{

	protected function traitTarget(): void
	{
		$this->template->traitVar = 'from-override';
	}

}
