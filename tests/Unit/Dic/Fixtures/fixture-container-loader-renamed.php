<?php declare(strict_types = 1);

use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\FixtureContainerFactory;

require_once __DIR__ . '/../../../../tests/autoload.php';

$factory = new FixtureContainerFactory();

// Same two container FILES as fixture-container-loader.php, one under a different profile name.
return [
	'alpha' => $factory->create('alpha'),
	'gamma' => $factory->create('beta'),
];
