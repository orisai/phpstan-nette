<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Presenter;

final class TemplateClassFactoryStaticPresenter extends Presenter
{

	public function actionDefault(): void
	{
		TemplateClassChannelTargetThree::create();
	}

}
