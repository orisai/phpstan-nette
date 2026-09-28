<?php declare(strict_types = 1);

use Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures\FixtureLatteFactoryAlternateMacroClass;

require_once __DIR__ . '/../../../../../vendor/autoload.php';

return (new FixtureLatteFactoryAlternateMacroClass())->create();
