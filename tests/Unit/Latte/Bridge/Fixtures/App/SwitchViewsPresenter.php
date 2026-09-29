<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Presenter;

final class SwitchViewsPresenter extends Presenter
{

	public function actionDefault(): void
	{
		$this->switch('other');
	}

	public function actionOther(): void
	{
	}

	public function renderThird(): void
	{
		$this->switch('fourth');
	}

}
