<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Type\Fixtures\TypeInference;

use Container_5011b2e17d;
use Container_6a5cfd235d;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\CustomContainer;
use function PHPStan\Testing\assertType;

function (Container_5011b2e17d $container): void {
	assertType('true', $container->hasService('alphaOnly'));
	assertType('bool', $container->hasService('betaOnly'));
	assertType('Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\FooService', $container->getService('foo'));
	assertType('Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\AlphaOnlyService', $container->getService('alphaOnly'));
	assertType("'Tests\\\\OriPhpstan\\\\Nette\\\\Unit\\\\Dic\\\\Fixtures\\\\App\\\\FooService'", $container->getServiceType('foo'));

	$params = $container->getParameters();
	assertType('int', $params['mixedTypeParam']);
	assertType('array{shared: string, alphaOnly: list<string>}', $params['nested']);
	assertType('string', $params['alphaOnlyParam']);
};

function (Container_6a5cfd235d $container): void {
	assertType('Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\FooServiceBeta', $container->getService('foo'));

	$params = $container->getParameters();
	assertType('string', $params['mixedTypeParam']);
	assertType('array{shared: string}', $params['nested']);
};

/**
 * @param Container_5011b2e17d|Container_6a5cfd235d $container
 */
function receiverUnion($container): void
{
	assertType(
		'Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\FooService|Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\FooServiceBeta',
		$container->getService('foo'),
	);
}

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
