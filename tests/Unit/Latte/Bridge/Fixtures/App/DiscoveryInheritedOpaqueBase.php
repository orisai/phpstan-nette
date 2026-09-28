<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Control;

abstract class DiscoveryInheritedOpaqueBase extends Control
{

	public function render(): void
	{
		$path = $this->buildPath();
		$this->getTemplate()->setFile($path);
	}

	private function buildPath(): string
	{
		return 'inherited-dynamic.latte';
	}

}
