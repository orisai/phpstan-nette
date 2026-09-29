<?php declare(strict_types = 1);

use Latte\Engine;
use Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures\FixtureTypedCustoms;

require_once __DIR__ . '/../../../../../tests/autoload.php';

$engine = new Engine();
$engine->addFilter('myFilter', [FixtureTypedCustoms::class, 'myFilter']);
$engine->addFunction('myFunction', [FixtureTypedCustoms::class, 'myFunction']);

return $engine;
