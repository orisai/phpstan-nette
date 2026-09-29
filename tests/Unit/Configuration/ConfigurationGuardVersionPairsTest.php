<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Configuration;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Configuration\InvalidConfiguration;
use OriPhpstan\Nette\Support\ProjectInstalledVersions;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Testing\PHPStanTestCase;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;

/**
 * @phpstan-import-type OrisaiNetteConfig from ConfigurationGuard
 */
final class ConfigurationGuardVersionPairsTest extends BaseTestCase
{

	/**
	 * @param array<string, string> $versions
	 *
	 * @dataProvider provideRejected
	 */
	public function testRejectedPair(array $versions, string $message): void
	{
		$guard = $this->guard(true, $versions);

		$this->expectException(InvalidConfiguration::class);
		$this->expectExceptionMessage($message);

		$guard->validate();
	}

	/**
	 * @return iterable<string, array{array<string, string>, string}>
	 */
	public function provideRejected(): iterable
	{
		yield 'latte 3.0 with forms before FormsExtension' => [
			['latte/latte' => '3.0.26.0', 'nette/forms' => '3.1.6.0', 'nette/application' => '3.1.15.0'],
			'Latte 3 requires nette/forms >= 3.1.7 (FormsExtension); installed 3.1.6.0.',
		];

		yield 'latte 3.0 with application before UIExtension' => [
			['latte/latte' => '3.0.26.0', 'nette/forms' => '3.1.15.0', 'nette/application' => '3.1.5.0'],
			'Latte 3 requires nette/application >= 3.1.6 (UIExtension); installed 3.1.5.0.',
		];

		yield 'forms row wins over the application row' => [
			['latte/latte' => '3.1.6.0', 'nette/forms' => '3.1.6.0', 'nette/application' => '3.1.5.0'],
			'Latte 3 requires nette/forms >= 3.1.7 (FormsExtension); installed 3.1.6.0.',
		];

		yield 'latte 3.1 with the conflicting forms 3.2 line' => [
			['latte/latte' => '3.1.6.0', 'nette/forms' => '3.2.6.0', 'nette/application' => '3.2.12.0'],
			'Latte 3.1 requires nette/forms >= 3.2.7 and nette/application >= 3.2.10; installed 3.2.6.0/3.2.12.0.',
		];

		yield 'latte 3.1 with the conflicting application 3.2 line' => [
			['latte/latte' => '3.1.6.0', 'nette/forms' => '3.2.9.0', 'nette/application' => '3.2.9.0'],
			'Latte 3.1 requires nette/forms >= 3.2.7 and nette/application >= 3.2.10; installed 3.2.9.0/3.2.9.0.',
		];

		yield 'latte 3.1 with forms 3.1 (never allowed Latte 3.1)' => [
			['latte/latte' => '3.1.6.0', 'nette/forms' => '3.1.15.0', 'nette/application' => '3.3.0.0'],
			'Latte 3.1 requires nette/forms >= 3.2.7 and nette/application >= 3.2.10; installed 3.1.15.0/3.3.0.0.',
		];

		yield 'latte 3.1 without forms but old application' => [
			['latte/latte' => '3.1.6.0', 'nette/application' => '3.2.9.0'],
			'Latte 3.1 requires nette/forms >= 3.2.7 and nette/application >= 3.2.10; installed none/3.2.9.0.',
		];
	}

	/**
	 * @param array<string, string> $versions
	 *
	 * @dataProvider provideAccepted
	 */
	public function testAcceptedPair(bool $latteEnabled, array $versions): void
	{
		$this->expectNotToPerformAssertions();

		$this->guard($latteEnabled, $versions)->validate();
	}

	/**
	 * @return iterable<string, array{bool, array<string, string>}>
	 */
	public function provideAccepted(): iterable
	{
		yield 'latte 2 ignores the rows' => [
			true,
			['latte/latte' => '2.11.7.0', 'nette/forms' => '3.1.6.0', 'nette/application' => '3.1.5.0'],
		];

		yield 'latte disabled ignores the rows' => [
			false,
			['latte/latte' => '3.1.6.0', 'nette/forms' => '3.1.6.0', 'nette/application' => '3.1.5.0'],
		];

		yield 'latte 3.0 with the first bridge releases' => [
			true,
			['latte/latte' => '3.0.26.0', 'nette/forms' => '3.1.7.0', 'nette/application' => '3.1.6.0'],
		];

		yield 'latte 3.0 with the 3.2 lines' => [
			true,
			['latte/latte' => '3.0.26.0', 'nette/forms' => '3.2.6.0', 'nette/application' => '3.2.9.0'],
		];

		yield 'latte 3.1 with the first allowing 3.2 releases' => [
			true,
			['latte/latte' => '3.1.6.0', 'nette/forms' => '3.2.7.0', 'nette/application' => '3.2.10.0'],
		];

		yield 'latte 3.1 with the 3.3 lines' => [
			true,
			['latte/latte' => '3.1.6.0', 'nette/forms' => '3.3.0.0', 'nette/application' => '3.3.0.0'],
		];

		yield 'latte 3 without the bridges installed' => [true, ['latte/latte' => '3.1.6.0']];

		yield 'latte not installed' => [true, []];
	}

	/**
	 * @param array<string, string> $versions
	 */
	private function guard(bool $latteEnabled, array $versions): ConfigurationGuard
	{
		$installed = [ProjectInstalledVersions::PACKAGE => ['version' => '1.0.0.0']];
		foreach ($versions as $package => $version) {
			$installed[$package] = ['version' => $version];
		}

		return new ConfigurationGuard(
			self::config($latteEnabled),
			['php', 'latte'],
			PHPStanTestCase::getContainer()->getByType(ReflectionProvider::class),
			ProjectInstalledVersions::fromRawData([['root' => [], 'versions' => $installed]]),
		);
	}

	/**
	 * @return OrisaiNetteConfig
	 */
	private static function config(bool $latteEnabled): array
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
				'enabled' => $latteEnabled,
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
