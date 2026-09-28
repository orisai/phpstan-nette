<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Type\Fixtures\TypeInference;

use Nette\DI\Container;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\AlphaOnlyService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\DuplicateService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\FooService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\SetupService;
use Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\Widget;
use function PHPStan\Testing\assertType;

function (Container $container, string $dynamicName, bool $flag): void {
	assertType('true', $container->hasService('foo'));
	assertType('true', $container->hasService('fooRealAlias'));
	assertType('bool', $container->hasService('alphaOnly'));
	assertType('bool', $container->hasService('nonexistent'));
	assertType('bool', $container->hasService('chainedAlias'));
	assertType('bool', $container->hasService($dynamicName));
	assertType('true', $container->hasService($flag ? 'foo' : 'sharedName'));
	assertType('bool', $container->hasService($flag ? 'foo' : 'alphaOnly'));

	assertType(
		'Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\FooService|Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\FooServiceBeta',
		$container->getService('foo'),
	);
	assertType(
		'Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\FooService|Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\FooServiceBeta',
		$container->getByName('foo'),
	);
	assertType(
		'Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\FooService|Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\FooServiceBeta',
		$container->createService('foo'),
	);
	assertType(
		'Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\FooService|Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\FooServiceBeta',
		$container->getService('fooAlias'),
	);
	assertType(
		'Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\AlphaVariant|Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\BetaVariant',
		$container->getService('sharedName'),
	);
	assertType('Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\AlphaOnlyService', $container->getService('alphaOnly'));
	assertType('Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\ImportedService', $container->getService('imported'));
	assertType('object', $container->getService('nonexistent'));
	assertType('object', $container->getService($dynamicName));
	assertType(
		'Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\FooService|Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\FooServiceBeta',
		$container->getService('chainedAlias'),
	);

	assertType('Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\SetupService', $container->getByType(SetupService::class));
	assertType(
		'Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\SetupService',
		$container->getByType(SetupService::class, false),
	);
	assertType('Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\SetupService', $container->getByType(SetupService::class, true));
	assertType(
		'Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\AlphaOnlyService|null',
		$container->getByType(AlphaOnlyService::class, false),
	);
	assertType(
		'Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\DuplicateService|null',
		$container->getByType(DuplicateService::class, false),
	);
	assertType('object', $container->getByType($dynamicName));
	assertType('object|null', $container->getByType($dynamicName, false));
	assertType('Tests\OriPhpstan\Nette\Unit\Dic\Fixtures\App\Widget', $container->createInstance(Widget::class));

	assertType("array{'dupA', 'dupB'}", $container->findByType(DuplicateService::class));
	assertType('list<string>', $container->findByType(FooService::class)); // differs across profiles
	assertType('array{}', $container->findByType('Tests\\Nowhere\\Unknown'));

	assertType('array<string, array{priority: int}>', $container->findByTag('shared.tag'));
	assertType('array{}', $container->findByTag('no.such.tag'));
	assertType('array<string, mixed>', $container->findByTag($dynamicName));

	assertType(
		"'Tests\\\\OriPhpstan\\\\Nette\\\\Unit\\\\Dic\\\\Fixtures\\\\App\\\\AlphaOnlyService'",
		$container->getServiceType('alphaOnly'),
	);
	assertType(
		"'Tests\\\\OriPhpstan\\\\Nette\\\\Unit\\\\Dic\\\\Fixtures\\\\App\\\\AlphaVariant'|'Tests\\\\OriPhpstan\\\\Nette\\\\Unit\\\\Dic\\\\Fixtures\\\\App\\\\BetaVariant'",
		$container->getServiceType('sharedName'),
	);
	assertType('string', $container->getServiceType($dynamicName));
	assertType(
		"'Tests\\\\OriPhpstan\\\\Nette\\\\Unit\\\\Dic\\\\Fixtures\\\\App\\\\FooService'|'Tests\\\\OriPhpstan\\\\Nette\\\\Unit\\\\Dic\\\\Fixtures\\\\App\\\\FooServiceBeta'",
		$container->getServiceType('chainedAlias'),
	);

	$params = $container->getParameters();
	assertType('int', $params['intParam']);
	assertType('int|string', $params['mixedTypeParam']);
	assertType('string', $params['alphaOnlyParam']);
	assertType('string', $container->parameters['stringParam']);
	assertType('array{shared: string, alphaOnly?: list<string>}', $params['nested']);
};

/**
 * @template T of object
 * @param class-string<T> $type
 * @return T
 */
function templatedLookup(Container $container, string $type): object
{
	$service = $container->getByType($type);
	assertType(
		'T of object (function Tests\OriPhpstan\Nette\Unit\Dic\Type\Fixtures\TypeInference\templatedLookup(), argument)',
		$service,
	);

	return $service;
}
