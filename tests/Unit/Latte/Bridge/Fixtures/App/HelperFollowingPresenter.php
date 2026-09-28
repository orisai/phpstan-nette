<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Presenter;

final class HelperFollowingPresenter extends Presenter
{

	use HelperSetterTrait;

	public function actionDefault(): void
	{
		$this->helperSetter();
	}

}
