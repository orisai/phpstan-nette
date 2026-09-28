<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Presenter;

final class CycleTerminationPresenter extends Presenter
{

	use CycleHelperTrait;

	public function actionDefault(): void
	{
		$this->traitStep();
	}

	private function hostStep(): void
	{
		$this->traitStep();
	}

}
