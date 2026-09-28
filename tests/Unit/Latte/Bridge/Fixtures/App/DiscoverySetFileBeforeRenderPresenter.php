<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Presenter;

final class DiscoverySetFileBeforeRenderPresenter extends Presenter
{

	public function actionDefault(): void
	{
	}

	protected function beforeRender(): void
	{
		$this->getTemplate()->setFile(__DIR__ . '/custom-shared.latte');
	}

}
