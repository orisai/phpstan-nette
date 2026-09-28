<?php declare(strict_types = 1);

use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\FixtureContainerFactory;

require_once __DIR__ . '/../../../../vendor/autoload.php';

$factory = new FixtureContainerFactory();

// A third profile whose container FILE is already in the digest under another name.
return [
	'alpha' => $factory->create('alpha'),
	'beta' => $factory->create('beta'),
	'epsilon' => $factory->create('beta'),
];
