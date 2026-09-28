<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\Response;
use Nette\Application\UI\Presenter;

final class PublicShutdownHelperViewPresenter extends Presenter
{

	protected function shutdown(Response $response): void
	{
		$this->applyLate();
	}

	public function applyLate(): void
	{
		$this->setView('late');
		$this->applyInner();
	}

	private function applyInner(): void
	{
		$this->changeAction('inner');
	}

}
