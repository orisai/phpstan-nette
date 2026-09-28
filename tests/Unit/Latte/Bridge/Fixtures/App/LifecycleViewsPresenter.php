<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Presenter;

final class LifecycleViewsPresenter extends Presenter
{

	public bool $flag = false;

	protected function startup(): void
	{
		$this->setView('fromStartup');
	}

	public function checkRequirements($element): void
	{
		$this->setView('fromCheck');
	}

	public function actionDefault(): void
	{
	}

	public function actionOther(): void
	{
		if ($this->flag) {
			$this->setView('conditional');
		}
	}

	public function renderDetail(): void
	{
	}

	protected function beforeRender(): void
	{
		$this->setView('altBefore');
	}

	protected function afterRender(): void
	{
		$this->setView('afterR');
	}

}
