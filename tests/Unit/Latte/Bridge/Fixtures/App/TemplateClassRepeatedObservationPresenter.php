<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Presenter;

final class TemplateClassRepeatedObservationPresenter extends Presenter
{

	/** @var bool */
	public $flag = false;

	public function actionDefault(): void
	{
		if ($this->flag) {
			TemplateClassChannelTargetThree::create();
		}

		TemplateClassChannelTargetThree::create();
	}

}
