<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Rule;

use Nette\Neon\Neon;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Includes\SiteScopeStore;
use ReflectionMethod;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function dirname;
use function getmypid;
use function strpos;
use function sys_get_temp_dir;
use function uniqid;
use const PHP_BINARY;

// A real subprocess spawn (mirrors IntegrationSnapshotTest), not RuleTestCase: RuleTestCase's own
// container pins %currentWorkingDirectory% to the phpstan library's internal root (see
// wiring.neon's orisai.nette.latte.narrowing.storePath comment), so LatteUniverse's own project-root containment
// guard rejects every fixture path before EdgeAnchorInjector ever runs. Only a spawn from the real
// repo root exercises the collector against genuinely narrowed PHPStan types.
/**
 * @group latte2
 */
final class LatteSiteScopeCaptureIntegrationTest extends BaseTestCase
{

	private const FixtureDir = __DIR__ . '/../../../Unit/Latte/Fixtures/Rule/SiteScopeCapture';

	public function testCapturesNarrowedAmbientVarAndCompoundArgExpressionEndToEnd(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratchDir = sys_get_temp_dir() . '/latte-sitescope-capture-' . getmypid() . '-' . uniqid('', true);
		FileSystem::createDir($scratchDir);

		try {
			$storeDir = $scratchDir . '/store';
			// The bootstrap gate (LatteSiteScopeWriterRule) only writes when the store directory
			// already exists - pre-seed it so this spawn can observe a real write.
			SiteScopeStore::bootstrap($storeDir, []);

			$configPath = $this->writeWrapperConfig($scratchDir, $storeDir);

			$process = new Process(
				[
					PHP_BINARY,
					$projectRoot . '/' . VendorDirectory::name() . '/bin/phpstan',
					'analyse',
					'--no-progress',
					'--level=8',
					'--error-format=raw',
					'-c',
					$configPath,
				],
				$projectRoot,
			);
			$process->setTimeout(120.0);
			$process->run();

			$store = new SiteScopeStore($storeDir);
			$raw = $this->allEntries($store);
			self::assertNotSame(
				[],
				$raw,
				'spawn must produce a valid store slice: ' . $process->getOutput() . $process->getErrorOutput(),
			);

			$narrowedEntry = $this->findEntry($store, $raw, '#narrowed-target.latte#');
			self::assertNotNull($narrowedEntry, 'file-form anchor must be captured: ' . $process->getOutput());
			self::assertSame(
				'stdClass',
				$narrowedEntry['vars']['x'] ?? null,
				'the {if $x !== null} guard must narrow the manifest-captured var from stdClass|null to stdClass',
			);

			$wrapEntry = $this->findEntry($store, $raw, '#wrap#');
			self::assertNotNull($wrapEntry, 'untyped-param block anchor must be captured');
			self::assertSame(
				'non-falsy-string',
				$wrapEntry['args']['inner'] ?? null,
				'the compound arg expression $y . \'!\' must be captured by its own real type',
			);
		} finally {
			FileSystem::delete($scratchDir);
		}
	}

	private function writeWrapperConfig(string $scratchDir, string $storeDir): string
	{
		$configPath = $scratchDir . '/wrapper.neon';
		FileSystem::write(
			$configPath,
			Neon::encode(
				[
					'includes' => [__DIR__ . '/../Integration/Fixtures/integration.neon'],
					'parameters' => [
						'tmpDir' => $scratchDir,
						'fileExtensions' => ['php', 'latte'],
						'orisai' => ['nette' => [
							'latte' => [
								'enabled' => true,
								'narrowing' => ['storePath' => $storeDir],
							],
						]],
						'paths!' => [self::FixtureDir],
					],
				],
				true,
			),
		);

		return $configPath;
	}

	// SiteScopeStore's own directory load is lazy - reflecting
	// into its private entries() accessor (rather than the backing property directly) both
	// triggers that load and returns the result in one step, staying correct regardless of how
	// the lazy-cache is internally represented.

	/**
	 * @return array<string, array{sha: string, vars: array<string, string>, args: array<string, string>}>
	 */
	private function allEntries(SiteScopeStore $store): array
	{
		$method = new ReflectionMethod(SiteScopeStore::class, 'entries');
		$method->setAccessible(true);

		/** @var array<string, array{sha: string, vars: array<string, string>, args: array<string, string>}> $entries */
		$entries = $method->invoke($store);

		return $entries;
	}

	/**
	 * @param array<string, array{sha: string, vars: array<string, string>, args: array<string, string>}> $raw
	 * @return array{sha: string, vars: array<string, string>, args: array<string, string>}|null
	 */
	private function findEntry(SiteScopeStore $store, array $raw, string $rawTargetFragment): ?array
	{
		foreach ($raw as $key => $entry) {
			if (strpos($key, $rawTargetFragment) === false) {
				continue;
			}

			$found = $store->get($key, $entry['sha']);
			if ($found !== null) {
				return $found;
			}
		}

		return null;
	}

}
