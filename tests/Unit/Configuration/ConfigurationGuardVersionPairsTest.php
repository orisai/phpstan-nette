<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Configuration;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Configuration\InvalidConfiguration;
use OriPhpstan\Nette\Support\ProjectInstalledVersions;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;
use function preg_replace;

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
		yield 'latte branch without a number' => [
			['latte/latte' => 'dev-master', 'nette/forms' => '3.3.0.0', 'nette/application' => '3.3.0.0'],
			'orisaiNette.latte.enabled requires a supported latte/latte version (2.11, 3.0 or 3.1); installed dev-master.',
		];

		yield 'latte 3.2' => [
			['latte/latte' => '3.2.0.0', 'nette/forms' => '3.3.0.0', 'nette/application' => '3.3.0.0'],
			'orisaiNette.latte.enabled requires a supported latte/latte version (2.11, 3.0 or 3.1); installed v3.2.0.',
		];

		yield 'latte 3.0 with forms before FormsExtension' => [
			['latte/latte' => '3.0.26.0', 'nette/forms' => '3.1.6.0', 'nette/application' => '3.1.15.0'],
			'Latte 3 requires nette/forms >= 3.1.7 (FormsExtension); installed v3.1.6.',
		];

		yield 'latte 3.0 with application before UIExtension' => [
			['latte/latte' => '3.0.26.0', 'nette/forms' => '3.1.15.0', 'nette/application' => '3.1.5.0'],
			'Latte 3 requires nette/application >= 3.1.6 (UIExtension); installed v3.1.5.',
		];

		yield 'forms row wins over the application row' => [
			['latte/latte' => '3.1.6.0', 'nette/forms' => '3.1.6.0', 'nette/application' => '3.1.5.0'],
			'Latte 3 requires nette/forms >= 3.1.7 (FormsExtension); installed v3.1.6.',
		];

		yield 'latte 3.1 with the conflicting forms 3.2 line' => [
			['latte/latte' => '3.1.6.0', 'nette/forms' => '3.2.6.0', 'nette/application' => '3.2.12.0'],
			'Latte 3.1 requires nette/forms >= 3.2.7 and nette/application >= 3.2.7; installed v3.2.6/v3.2.12.',
		];

		yield 'latte 3.1 with the conflicting application 3.2 line' => [
			['latte/latte' => '3.1.6.0', 'nette/forms' => '3.2.9.0', 'nette/application' => '3.2.6.0'],
			'Latte 3.1 requires nette/forms >= 3.2.7 and nette/application >= 3.2.7; installed v3.2.9/v3.2.6.',
		];

		yield 'latte 3.1 with forms 3.1 (never allowed Latte 3.1)' => [
			['latte/latte' => '3.1.6.0', 'nette/forms' => '3.1.15.0', 'nette/application' => '3.3.0.0'],
			'Latte 3.1 requires nette/forms >= 3.2.7 and nette/application >= 3.2.7; installed v3.1.15/v3.3.0.',
		];

		yield 'latte 3.1 without forms but old application' => [
			['latte/latte' => '3.1.6.0', 'nette/application' => '3.2.6.0'],
			'Latte 3.1 requires nette/forms >= 3.2.7 and nette/application >= 3.2.7; installed none/v3.2.6.',
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
		yield 'latte 2 ignores the pair rows' => [
			true,
			['latte/latte' => '2.11.7.0', 'nette/forms' => '3.1.6.0', 'nette/application' => '3.1.5.0'],
		];

		yield 'latte disabled ignores every row' => [
			false,
			['latte/latte' => 'dev-master', 'nette/forms' => '3.1.6.0', 'nette/application' => '3.1.5.0'],
		];

		yield 'latte 3.1 branch alias parses' => [
			true,
			['latte/latte' => '3.1.x-dev', 'nette/forms' => '3.3.0.0', 'nette/application' => '3.3.0.0'],
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
			['latte/latte' => '3.1.6.0', 'nette/forms' => '3.2.7.0', 'nette/application' => '3.2.7.0'],
		];

		yield 'latte 3.1 with the 3.3 lines' => [
			true,
			['latte/latte' => '3.1.6.0', 'nette/forms' => '3.3.0.0', 'nette/application' => '3.3.0.0'],
		];

		yield 'latte 3 without the bridges installed' => [true, ['latte/latte' => '3.1.6.0']];

		yield 'latte not installed falls back to the loaded engine' => [true, []];
	}

	/**
	 * @param array<string, string> $versions
	 */
	private function guard(bool $latteEnabled, array $versions): ConfigurationGuard
	{
		$installed = [ProjectInstalledVersions::PACKAGE => ['version' => '1.0.0.0', 'pretty_version' => '1.0.0']];
		foreach ($versions as $package => $version) {
			$installed[$package] = ['version' => $version, 'pretty_version' => self::pretty($version)];
		}

		return TestGuard::latte(
			$latteEnabled,
			false,
			false,
			null,
			ProjectInstalledVersions::fromRawData([['root' => [], 'versions' => $installed]]),
		);
	}

	// Composer's own convention: a tagged release keeps its 'v' prefix, a branch alias its '-dev' form.
	private static function pretty(string $normalized): string
	{
		if ($normalized === 'dev-master' || $normalized === '3.1.x-dev') {
			return $normalized;
		}

		return 'v' . preg_replace('~\.0$~', '', $normalized);
	}

}
