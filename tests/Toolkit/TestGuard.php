<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use PHPStan\DependencyInjection\Container;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Testing\PHPStanTestCase;
use function assert;
use function is_array;

final class TestGuard
{

	public static function of(Container $container): ConfigurationGuard
	{
		$guard = $container->getService('orisaiNette.configurationGuard');
		assert($guard instanceof ConfigurationGuard);

		return $guard;
	}

	public static function withContainerLoader(Container $container, string $loaderFile): ConfigurationGuard
	{
		$config = $container->getParameter('orisaiNette');
		assert(is_array($config));
		$config['dic']['containerLoader'] = $loaderFile;

		$fileExtensions = $container->getParameter('fileExtensions');
		assert(is_array($fileExtensions));

		return new ConfigurationGuard(
			$config, // @phpstan-ignore argument.type
			$fileExtensions, // @phpstan-ignore argument.type
			$container->getByType(ReflectionProvider::class),
		);
	}

	// Every other key at its default; narrowing or discovery on implies Latte on, as the guard requires.
	public static function latte(
		bool $enabled = true,
		bool $narrowing = false,
		bool $discovery = false
	): ConfigurationGuard
	{
		return new ConfigurationGuard(
			[
				'forms' => [
					'enabled' => true,
					'defaultContainerClass' => 'Nette\\Forms\\Container',
					'reportUnannotatedRegistrars' => true,
					'catalogs' => [],
					'internals' => ['indexShadowCompare' => false],
				],
				'component' => ['enabled' => true],
				'latte' => [
					'enabled' => $enabled || $narrowing || $discovery,
					'narrowing' => ['enabled' => $narrowing, 'storePath' => 'phpstan-latte-store'],
					'discovery' => [
						'enabled' => $discovery,
						'storePath' => 'latte-discovery',
						'coarseInvalidationAccepted' => false,
						'formulas' => [],
					],
					'engineLoader' => null,
					'templateFactoryContainerLoader' => null,
					'firstPartyPaths' => [],
					'templateTypeRequired' => false,
					'includeIsolation' => false,
					'allowNarrowingOverride' => false,
					'reportWrongPhpDocTypeInVarType' => true,
					'reportAnyTypeWideningInVarType' => true,
				],
				'dic' => ['containerLoader' => null],
			],
			['php', 'latte'],
			PHPStanTestCase::getContainer()->getByType(ReflectionProvider::class),
		);
	}

}
