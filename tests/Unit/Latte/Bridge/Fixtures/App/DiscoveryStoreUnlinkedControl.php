<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Control;

final class DiscoveryStoreUnlinkedControl extends Control
{

	public function render(): void
	{
		$this->template->setFile(__DIR__ . '/discoveryStoreMissing.latte');
		$this->template->render();
	}

}
