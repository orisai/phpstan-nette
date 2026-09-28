<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Type\Fixtures\TypeInference;

use Nette\DI\Container;
use function PHPStan\Testing\assertType;

function (Container $container): void {
	if ($container->hasService('alphaOnly')) {
		assertType('Nette\DI\Container&hasMethod(createServiceAlphaOnly)', $container);
		assertType('Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\AlphaOnlyService', $container->getService('alphaOnly'));
	} else {
		assertType('Nette\DI\Container~service:createServiceAlphaOnly', $container);
	}
};

function (Container $container): void {
	if (!$container->hasService('foo')) {
		return;
	}

	assertType('Nette\DI\Container&hasMethod(createServiceFoo)', $container);
};

function (Container $container): void {
	if ($container->hasService('fooRealAlias')) {
		assertType('Nette\DI\Container&hasMethod(createServiceFoo)', $container);
	}
};
