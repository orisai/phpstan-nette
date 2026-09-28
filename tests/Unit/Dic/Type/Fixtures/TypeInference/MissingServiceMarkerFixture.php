<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Type\Fixtures\TypeInference;

use Nette\DI\Container;
use function PHPStan\Testing\assertType;

function (Container $container): void {
	if ($container->hasService('alphaOnly')) {
		$x = 1;
	} else {
		assertType('Nette\DI\Container~service:createServiceAlphaOnly', $container);
	}
};

function (Container $container): void {
	if (!$container->hasService('alphaOnly')) {
		assertType('Nette\DI\Container~service:createServiceAlphaOnly', $container);

		return;
	}
};

function (Container $container): void {
	$container->hasService('alphaOnly')
		? null
		: assertType(
			'Nette\DI\Container~service:createServiceAlphaOnly',
			$container,
		);
};

function (Container $container): void {
	if (!$container->hasService('alphaOnly') && !$container->hasService('betaOnly')) {
		assertType('Nette\DI\Container~service:createServiceAlphaOnly,createServiceBetaOnly', $container);
	}
};

function (Container $container): void {
	if ($container->hasService('alphaOnly')) {
		$x = 1;
	} else {
		$y = 1;
	}

	assertType(
		'(Nette\DI\Container&hasMethod(createServiceAlphaOnly))|Nette\DI\Container~service:createServiceAlphaOnly',
		$container,
	);
};

function (Container $container): void {
	if ($container->hasService('alphaOnly')) {
		$x = 1;
	} elseif ($container->hasService('betaOnly')) {
		assertType(
			'Nette\DI\Container~service:createServiceAlphaOnly&hasMethod(createServiceBetaOnly)',
			$container,
		);
	} else {
		assertType(
			'Nette\DI\Container~service:createServiceAlphaOnly,createServiceBetaOnly',
			$container,
		);
	}
};

function (Container $container): void {
	if ($container->hasService('alphaOnly')) {
		$x = 1;
	} elseif ($container->hasService('alphaOnly')) {
		assertType(
			'Nette\DI\Container~service:createServiceAlphaOnly&hasMethod(createServiceAlphaOnly)',
			$container,
		);
	}
};
