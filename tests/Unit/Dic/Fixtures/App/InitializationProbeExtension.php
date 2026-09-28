<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App;

use Nette\DI\CompilerExtension;

final class InitializationProbeExtension extends CompilerExtension
{

	public function loadConfiguration(): void
	{
		$this->initialization->addBody('\Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\InitializedProbe::$initialized = true;');
	}

}
