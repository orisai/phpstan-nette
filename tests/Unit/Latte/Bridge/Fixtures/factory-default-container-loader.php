<?php declare(strict_types = 1);

use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\FixtureTemplateFactoryContainer;

require_once __DIR__ . '/../../../../../vendor/autoload.php';

return [
	'fixture' => new FixtureTemplateFactoryContainer(),
];
