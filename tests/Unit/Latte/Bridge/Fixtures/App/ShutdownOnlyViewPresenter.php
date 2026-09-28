<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\Response;
use Nette\Application\UI\Presenter;

final class ShutdownOnlyViewPresenter extends Presenter
{

	protected function shutdown(Response $response): void
	{
		$this->setView('never');
		$this->applyLate();
	}

	private function applyLate(): void
	{
		$this->changeAction('late');
	}

}
