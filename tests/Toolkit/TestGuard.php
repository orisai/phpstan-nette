<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Support\ProjectInstalledVersions;
use PHPStan\DependencyInjection\Container;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Testing\PHPStanTestCase;
use function assert;
use function is_array;

/**
 * @phpstan-import-type OrisaiNetteConfig from ConfigurationGuard
 */
final class TestGuard
{

	public static function of(Container $container): ConfigurationGuard
	{
		$guard = $container->getService('orisai.nette.configurationGuard');
		assert($guard instanceof ConfigurationGuard);

		return $guard;
	}

	public static function withContainerLoader(Container $container, string $loaderFile): ConfigurationGuard
	{
		$config = $container->getParameter('orisai')['nette'];
		assert(is_array($config));
		$config['dic']['containerLoader'] = $loaderFile;

		$fileExtensions = $container->getParameter('fileExtensions');
		assert(is_array($fileExtensions));

		return new ConfigurationGuard(
			$config, // @phpstan-ignore argument.type
			$fileExtensions, // @phpstan-ignore argument.type
			$container->getByType(ReflectionProvider::class),
			ProjectInstalledVersions::get(),
		);
	}

	// Every other key at its default; narrowing or discovery on implies Latte on, as the guard requires.
	public static function latte(
		bool $enabled = true,
		bool $narrowing = false,
		bool $discovery = false,
		?string $templateFactoryContainerLoader = null,
		?ProjectInstalledVersions $installedVersions = null
	): ConfigurationGuard
	{
		$config = self::defaults();
		$config['latte']['enabled'] = $enabled || $narrowing || $discovery;
		$config['latte']['narrowing']['enabled'] = $narrowing;
		$config['latte']['discovery']['enabled'] = $discovery;
		$config['latte']['templateFactoryContainerLoader'] = $templateFactoryContainerLoader;

		return self::create($config, $installedVersions);
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
	 * @param OrisaiNetteConfig $config
	 */
	private static function create(
		array $config,
		?ProjectInstalledVersions $installedVersions = null
	): ConfigurationGuard
	{
		return new ConfigurationGuard(
			$config,
			['php', 'latte'],
			PHPStanTestCase::getContainer()->getByType(ReflectionProvider::class),
			$installedVersions ?? ProjectInstalledVersions::get(),
		);
	}

	/**
	 * @return OrisaiNetteConfig
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
