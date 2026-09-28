<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

final class DiscoveryStoreLinkedControl extends FixtureStoreLinkedControlBase
{

	public function render(): void
	{
		$this->template->setFile(__DIR__ . '/discoveryStoreLinked.latte');
		$this->template->render();
	}

}
