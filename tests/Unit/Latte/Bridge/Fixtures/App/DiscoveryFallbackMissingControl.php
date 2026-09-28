<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

final class DiscoveryFallbackMissingControl extends FixtureFallbackMissingControlBase
{

	public function render(): void
	{
		$this->createTemplate()->render();
	}

}
