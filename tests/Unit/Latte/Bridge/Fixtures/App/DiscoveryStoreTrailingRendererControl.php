<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

final class DiscoveryStoreLeadingCompanion
{

	public function name(): string
	{
		return 'companion';
	}

}

final class DiscoveryStoreTrailingRendererControl extends FixtureStoreLinkedControlBase
{

	public function render(): void
	{
		$this->template->setFile(__DIR__ . '/discoveryStoreTrailingRenderer.latte');
		$this->template->render();
	}

}
