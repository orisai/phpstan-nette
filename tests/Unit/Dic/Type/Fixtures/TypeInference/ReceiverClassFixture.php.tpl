<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Type\Fixtures\TypeInference;

use {{alpha}};
use {{beta}};
use function PHPStan\Testing\assertType;

function ({{alpha}} $container): void {
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

function ({{beta}} $container): void {
	assertType('Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\FooServiceBeta', $container->getService('foo'));

	$params = $container->getParameters();
	assertType('string', $params['mixedTypeParam']);
	assertType('array{shared: string}', $params['nested']);
};

/**
 * @param {{alpha}}|{{beta}} $container
 */
function receiverUnion($container): void
{
	assertType(
		'Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\FooService|Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\FooServiceBeta',
		$container->getService('foo'),
	);
}
