<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Toolkit;

use Composer\InstalledVersions;
use Generator;
use LogicException;
use PHPUnit\Framework\SkippedTest;
use PHPUnit\Framework\TestSuite;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InstalledVersionsGuard;

final class InstalledVersionsGuardTest extends BaseTestCase
{

	private const LATTE2_NETTE31 = ['latte/latte' => '2.11.7.0', 'nette/application' => '3.1.15.0'];

	private const LATTE2_NETTE32 = ['latte/latte' => '2.11.7.0', 'nette/application' => '3.2.12.0'];

	private const LATTE30_NETTE32 = ['latte/latte' => '3.0.26.0', 'nette/application' => '3.2.12.0'];

	private const LATTE31_NETTE33 = ['latte/latte' => '3.1.6.0', 'nette/application' => '3.3.1.0'];

	private const COMPONENT_MODEL30 = ['nette/component-model' => '3.0.3.0'];

	private const COMPONENT_MODEL32 = ['nette/component-model' => '3.2.0.0'];

	private const COMPONENT_MODEL40 = ['nette/component-model' => '4.0.1.0'];

	protected function tearDown(): void
	{
		InstalledVersionsGuard::overrideVersions(null);
		parent::tearDown();
	}

	/**
	 * @param array<string, string> $versions
	 * @param list<string> $groups
	 *
	 * @dataProvider provideSkipDecisions
	 */
	public function testSkipDecision(array $versions, array $groups, ?string $reason): void
	{
		InstalledVersionsGuard::overrideVersions($versions);

		self::assertSame($reason, InstalledVersionsGuard::skipReason($groups));
	}

	/**
	 * @return Generator<string, array{array<string, string>, list<string>, string|null}>
	 */
	public function provideSkipDecisions(): Generator
	{
		yield 'ungrouped runs everywhere' => [self::LATTE2_NETTE31, [], null];
		yield 'unrelated groups run everywhere' => [self::LATTE31_NETTE33, ['slow', 'default'], null];

		yield 'latte2 on Latte 2' => [self::LATTE2_NETTE31, ['latte2'], null];
		yield 'latte2 on Latte 3.0' => [self::LATTE30_NETTE32, ['latte2'], 'requires Latte 2 (installed latte/latte 3.0.26.0)'];
		yield 'latte2 on Latte 3.1' => [self::LATTE31_NETTE33, ['latte2'], 'requires Latte 2 (installed latte/latte 3.1.6.0)'];

		yield 'latte3 on Latte 2' => [self::LATTE2_NETTE32, ['latte3'], 'requires Latte 3 (installed latte/latte 2.11.7.0)'];
		yield 'latte3 on Latte 3.0' => [self::LATTE30_NETTE32, ['latte3'], null];
		yield 'latte3 on Latte 3.1' => [self::LATTE31_NETTE33, ['latte3'], null];

		yield 'latte30 on Latte 2' => [self::LATTE2_NETTE32, ['latte30'], 'requires Latte 3.0 (installed latte/latte 2.11.7.0)'];
		yield 'latte30 on Latte 3.0' => [self::LATTE30_NETTE32, ['latte30'], null];
		yield 'latte30 on Latte 3.1' => [self::LATTE31_NETTE33, ['latte30'], 'requires Latte 3.0 (installed latte/latte 3.1.6.0)'];

		yield 'latte31 on Latte 2' => [self::LATTE2_NETTE32, ['latte31'], 'requires Latte 3.1 (installed latte/latte 2.11.7.0)'];
		yield 'latte31 on Latte 3.0' => [self::LATTE30_NETTE32, ['latte31'], 'requires Latte 3.1 (installed latte/latte 3.0.26.0)'];
		yield 'latte31 on Latte 3.1' => [self::LATTE31_NETTE33, ['latte31'], null];

		yield 'nette32 on 3.1' => [self::LATTE2_NETTE31, ['nette32'], 'requires nette/application 3.2 (installed nette/application 3.1.15.0)'];
		yield 'nette32 on 3.2' => [self::LATTE2_NETTE32, ['nette32'], null];
		yield 'nette32 on 3.3' => [self::LATTE31_NETTE33, ['nette32'], 'requires nette/application 3.2 (installed nette/application 3.3.1.0)'];

		yield 'nette33 on 3.2' => [self::LATTE30_NETTE32, ['nette33'], 'requires nette/application 3.3 (installed nette/application 3.2.12.0)'];
		yield 'nette33 on 3.3' => [self::LATTE31_NETTE33, ['nette33'], null];

		yield 'componentModel3 on 3.0' => [self::COMPONENT_MODEL30, ['componentModel3'], null];

		yield 'componentModel3 on 4.0' => [
			self::COMPONENT_MODEL40,
			['componentModel3'],
			'requires nette/component-model 3 (installed nette/component-model 4.0.1.0)',
		];

		yield 'componentModel4 on 3.2' => [
			self::COMPONENT_MODEL32,
			['componentModel4'],
			'requires nette/component-model 4 (installed nette/component-model 3.2.0.0)',
		];

		yield 'componentModel4 on 4.0' => [self::COMPONENT_MODEL40, ['componentModel4'], null];

		yield 'every group must hold' => [
			self::LATTE30_NETTE32,
			['latte3', 'nette33'],
			'requires nette/application 3.3 (installed nette/application 3.2.12.0)',
		];

		yield 'all groups met' => [self::LATTE30_NETTE32, ['latte3', 'latte30', 'nette32'], null];

		yield 'missing package fails the requirement' => [
			['nette/application' => '3.2.12.0'],
			['latte3'],
			'requires Latte 3 (installed latte/latte none)',
		];
	}

	public function testUnknownVersionGroupFailsLoudly(): void
	{
		InstalledVersionsGuard::overrideVersions(self::LATTE31_NETTE33);

		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('Unknown version group "latte32".');

		InstalledVersionsGuard::skipReason(['latte3', 'latte32']);
	}

	public function testUnknownComponentModelGroupFailsLoudly(): void
	{
		InstalledVersionsGuard::overrideVersions(self::COMPONENT_MODEL40);

		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('Unknown version group "componentModel5".');

		InstalledVersionsGuard::skipReason(['componentModel5']);
	}

	public function testLatteMajorAndLine(): void
	{
		InstalledVersionsGuard::overrideVersions(self::LATTE2_NETTE32);
		self::assertSame(2, InstalledVersionsGuard::latteMajor());
		self::assertSame('2', InstalledVersionsGuard::latteLine());

		InstalledVersionsGuard::overrideVersions(self::LATTE30_NETTE32);
		self::assertSame(3, InstalledVersionsGuard::latteMajor());
		self::assertSame('3.0', InstalledVersionsGuard::latteLine());

		InstalledVersionsGuard::overrideVersions(self::LATTE31_NETTE33);
		self::assertSame(3, InstalledVersionsGuard::latteMajor());
		self::assertSame('3.1', InstalledVersionsGuard::latteLine());
	}

	public function testSatisfies(): void
	{
		InstalledVersionsGuard::overrideVersions(self::LATTE30_NETTE32);

		self::assertTrue(InstalledVersionsGuard::satisfies('latte/latte', '~3.0.26'));
		self::assertFalse(InstalledVersionsGuard::satisfies('latte/latte', '^3.1'));
		self::assertTrue(InstalledVersionsGuard::satisfies('nette/application', '^3.2.10 <3.3'));
		self::assertFalse(InstalledVersionsGuard::satisfies('nette/forms', '*'));
	}

	public function testRequireLatteMajorSkips(): void
	{
		InstalledVersionsGuard::overrideVersions(self::LATTE2_NETTE32);
		InstalledVersionsGuard::requireLatteMajor(2);

		self::assertSame(
			'requires Latte 3 (installed latte/latte 2.11.7.0)',
			self::skipMessage(static function (): void {
				InstalledVersionsGuard::requireLatteMajor(3);
			}),
		);
	}

	public function testRequireNetteLineSkips(): void
	{
		InstalledVersionsGuard::overrideVersions(self::LATTE2_NETTE32);
		InstalledVersionsGuard::requireNetteLine('nette/application', '~3.2.0');

		self::assertSame(
			'requires nette/application ~3.3.0 (installed nette/application 3.2.12.0)',
			self::skipMessage(static function (): void {
				InstalledVersionsGuard::requireNetteLine('nette/application', '~3.3.0');
			}),
		);
	}

	/**
	 * @param array<string, string> $versions
	 *
	 * @dataProvider provideAnnotatedGroups
	 */
	public function testBaseTestCaseSkipsOnAnnotatedGroups(
		array $versions,
		int $skipped,
		int $runs,
		string $message
	): void
	{
		InstalledVersionsGuard::overrideVersions($versions);
		VersionGroupProbe::$runs = 0;

		$result = (new TestSuite(VersionGroupProbe::class))->run();

		self::assertCount(1, $result);
		self::assertSame($skipped, $result->skippedCount());
		self::assertSame($runs, VersionGroupProbe::$runs);
		self::assertTrue($result->wasSuccessfulAndNoTestIsRiskyOrSkippedOrIncomplete() === ($skipped === 0));
		foreach ($result->skipped() as $failure) {
			self::assertSame($message, $failure->exceptionMessage());
		}
	}

	/**
	 * @return Generator<string, array{array<string, string>, int, int, string}>
	 */
	public function provideAnnotatedGroups(): Generator
	{
		yield 'class group unmet' => [self::LATTE2_NETTE32, 1, 0, 'requires Latte 3 (installed latte/latte 2.11.7.0)'];
		yield 'method group unmet' => [self::LATTE31_NETTE33, 1, 0, 'requires nette/application 3.2 (installed nette/application 3.3.1.0)'];
		yield 'both met' => [self::LATTE30_NETTE32, 0, 1, ''];
	}

	public function testRealInstallIsRead(): void
	{
		self::assertSame(InstalledVersions::getVersion('latte/latte'), InstalledVersionsGuard::version('latte/latte'));
		self::assertNull(InstalledVersionsGuard::version('orisai/nonexistent-package'));
	}

	/**
	 * @param callable(): void $requirement
	 */
	private static function skipMessage(callable $requirement): ?string
	{
		try {
			$requirement();
		} catch (SkippedTest $skipped) {
			return $skipped->getMessage();
		}

		return null;
	}

	public function testVersionReadsThisProjectsVendorEvenWithPhpstansPharLoaderRegistered(): void
	{
		$project = null;
		foreach (InstalledVersions::getAllRawData() as $installed) {
			if ($installed['root']['name'] === 'orisai/phpstan-nette') {
				$project = $installed['versions'];
			}
		}

		self::assertNotNull($project);
		$pharAutoload = 'phar://' . ($project['phpstan/phpstan']['install_path'] ?? '') . '/phpstan.phar/vendor/autoload.php';
		self::assertFileExists($pharAutoload);
		require_once $pharAutoload;

		$bundled = null;
		foreach (InstalledVersions::getAllRawData() as $installed) {
			if ($installed['root']['name'] === 'phpstan/phpstan-src') {
				$bundled = $installed['versions'];
			}
		}

		self::assertNotNull($bundled, 'the phar loader must be registered by now');

		// A package both installs carry at different versions is the only one that can tell the two
		// answers apart; which one that is depends on the profile.
		foreach ($bundled as $package => $entry) {
			$expected = $project[$package]['version'] ?? null;
			$other = $entry['version'] ?? null;
			if ($expected === null || $other === null || $expected === $other) {
				continue;
			}

			self::assertSame($expected, InstalledVersionsGuard::version($package));
			self::assertSame(
				$other,
				InstalledVersions::getVersion($package),
				'the merged view answers with the bundled copy',
			);

			return;
		}

		self::markTestSkipped('this install and the phar bundle no package at different versions');
	}

}
