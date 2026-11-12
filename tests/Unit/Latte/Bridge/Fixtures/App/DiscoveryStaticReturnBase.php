<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Control;

abstract class DiscoveryStaticReturnBase extends Control
{

	public function render(): void
	{
		$this->getTemplate()->setFile($this->getFormTemplateFile());
		$this->getTemplate()->render();
	}

	abstract protected function getFormTemplateFile(): string;

}
