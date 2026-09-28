<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\Response;
use Nette\Application\UI\Presenter;

final class SetFilePhasePresenter extends Presenter
{

	protected function beforeRender(): void
	{
		$this->template->setFile(__DIR__ . '/phase-before.latte');
	}

	protected function shutdown(Response $response): void
	{
		$this->template->setFile(__DIR__ . '/phase-shutdown.latte');
	}

}
