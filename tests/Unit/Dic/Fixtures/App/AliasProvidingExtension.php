<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App;

use Nette\DI\CompilerExtension;

final class AliasProvidingExtension extends CompilerExtension
{

	public function beforeCompile(): void
	{
		$this->getContainerBuilder()->addAlias('fooRealAlias', 'foo');
		$this->getContainerBuilder()->addAlias('chainedAlias', 'fooRealAlias');
	}

}
