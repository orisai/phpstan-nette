<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\Response;
use Nette\Application\UI\Presenter;

final class TwoPhaseHelperViewPresenter extends Presenter
{

	protected function beforeRender(): void
	{
		$this->switchView();
	}

	protected function shutdown(Response $response): void
	{
		$this->switchView();
	}

	private function switchView(): void
	{
		$this->setView('contested');
	}

}
