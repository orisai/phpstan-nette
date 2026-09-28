<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Presenter;

final class DiscoverySetFileOpaquePresenter extends Presenter
{

	public function actionDefault(): void
	{
		$path = $this->buildPath();
		$this->getTemplate()->setFile($path);
	}

	private function buildPath(): string
	{
		return 'discovery-dynamic.latte';
	}

}
