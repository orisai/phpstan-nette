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
		bool $discovery = false,
		?string $templateFactoryContainerLoader = null
	): ConfigurationGuard
	{
		$config = self::defaults();
		$config['latte']['enabled'] = $enabled || $narrowing || $discovery;
		$config['latte']['narrowing']['enabled'] = $narrowing;
		$config['latte']['discovery']['enabled'] = $discovery;
		$config['latte']['templateFactoryContainerLoader'] = $templateFactoryContainerLoader;

		return self::create($config);
	}

	// The Latte-Forms bridge runs only with Forms, Latte and template discovery all on.
	public static function bridge(bool $forms = true, bool $latte = true, bool $discovery = true): ConfigurationGuard
	{
		$config = self::defaults();
		$config['forms']['enabled'] = $forms;
		$config['latte']['enabled'] = $latte;
		$config['latte']['discovery']['enabled'] = $discovery;

		return self::create($config);
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private static function create(array $config): ConfigurationGuard
	{
		return new ConfigurationGuard(
			$config, // @phpstan-ignore argument.type
			['php', 'latte'],
			PHPStanTestCase::getContainer()->getByType(ReflectionProvider::class),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function defaults(): array
	{
		return [
			'forms' => [
				'enabled' => true,
				'defaultContainerClass' => 'Nette\\Forms\\Container',
				'reportUnannotatedRegistrars' => true,
				'catalogs' => [],
				'internals' => ['indexShadowCompare' => false],
			],
			'component' => ['enabled' => true],
			'latte' => [
				'enabled' => true,
				'narrowing' => ['enabled' => false, 'storePath' => 'phpstan-latte-store'],
				'discovery' => [
					'enabled' => false,
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
		];
	}

}
