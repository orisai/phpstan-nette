<?php declare(strict_types = 1);

use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\FixtureContainerFactory;

require_once __DIR__ . '/../../../../tests/autoload.php';

$factory = new FixtureContainerFactory();

return [
	'alpha' => $factory->create('alpha'),
];
