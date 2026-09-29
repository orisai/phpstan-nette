<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Type\Fixtures\TypeInference\Nette31;

use Nette\DI\Container;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\CustomContainer;
use function PHPStan\Testing\assertType;

function (Container $container, string $dynamicName): void {
	assertType('string', $container->getServiceType($dynamicName));
};

function (CustomContainer $container): void {
	assertType('bool', $container->hasService('foo'));
	assertType('object', $container->getService('foo'));
	assertType('string', $container->getServiceType('foo'));
	assertType('array', $container->getParameters());
	assertType('array', $container->findByTag('shared.tag'));

	if ($container->hasService('foo')) {
		assertType('Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\CustomContainer', $container);
	}
};
