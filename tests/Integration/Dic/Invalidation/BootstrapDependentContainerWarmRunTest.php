<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Dic\Invalidation;

use Nette\Neon\Neon;
use Nette\Utils\FileSystem;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;
use function dirname;
use function implode;
use function str_replace;

// ResultCacheManager::restore() values every tracked key in the coordinator before %bootstrapFiles%
// run, the workers value them after. A container class only the bootstrap autoloader loads (as an
// application's compiled container is) makes the registry answers differ between the two, and a
// Forms catalog class loaded the same way makes the configuration guard reject the configuration in
// the coordinator only, unless both run the bootstrap files first - otherwise every warm run
// re-analyses every file asking the container something.
final class BootstrapDependentContainerWarmRunTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/../Fixtures/invalidation.neon';

	/**
	 * @return array<string, array{bool, list<string>}>
	 */
	public function provideBootstrapOnlyClasses(): array
	{
		return [
			'container class' => [true, []],
			'forms catalog class' => [false, ['ScratchCatalog']],
		];
	}

	/**
	 * @param list<string> $catalogs
	 *
	 * @dataProvider provideBootstrapOnlyClasses
	 */
	public function testWarmRunOnAnUnchangedTreeAnalysesNothing(bool $bootstrapOnlyContainer, array $catalogs): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scenario = InvalidationScenario::create(
			$projectRoot,
			'dic-bootstrap-warm',
			static function (array $paths, string $tmpDir) use ($catalogs): string {
				$scratchDir = dirname($tmpDir);
				FileSystem::createDir($tmpDir);
				$configPath = $tmpDir . '/wrapper.neon';
				FileSystem::write($configPath, Neon::encode(
					[
						'includes' => [self::REAL_CONFIG_PATH],
						'parameters' => [
							'tmpDir' => $tmpDir,
							'paths!' => $paths,
							'bootstrapFiles' => [$scratchDir . '/bootstrap.php'],
							'orisai' => [
								'nette' => [
									'dic' => ['containerLoader' => $scratchDir . '/loader.php'],
									'forms' => ['catalogs' => $catalogs],
								],
							],
						],
					],
					true,
				));

				return $configPath;
			},
		)->spawnWorkers();

		try {
			$this->seed($scenario, $projectRoot, $bootstrapOnlyContainer);

			$cold = $scenario->run();
			self::assertFalse($cold->isCacheRestored(), $cold->getDiagnostics());
			self::assertSame(
				"ScratchConsumer.php:6 :: shipmonk.deadMethod :: Unused ScratchConsumer::lookup\n"
				. 'ScratchConsumer.php:9 :: orisai.nette.dic.serviceNotFound :: Service \'missing\' is not registered in '
				. 'any analysed container (default).',
				$cold->getErrorText(),
			);

			$warm = $scenario->run();
			self::assertSame(
				[],
				$warm->getAnalysedFiles(),
				'a warm run on an unchanged tree must re-analyse nothing: ' . implode(', ', $warm->getAnalysedFiles())
				. "\n" . $warm->getDiagnostics(),
			);
			self::assertSame($cold->getErrorText(), $warm->getErrorText());
		} finally {
			$scenario->cleanup();
		}
	}

	private function seed(InvalidationScenario $scenario, string $projectRoot, bool $bootstrapOnlyContainer): void
	{
		$scratchDir = $scenario->getScratchDir();

		FileSystem::write($scratchDir . '/bootstrap.php', <<<'PHP'
<?php declare(strict_types = 1);

spl_autoload_register(static function (string $class): void {
	if (is_file(__DIR__ . '/lib/' . $class . '.php')) {
		require_once __DIR__ . '/lib/' . $class . '.php';
	}
});

PHP);

		FileSystem::write($scratchDir . '/lib/ScratchContainer.php', <<<'PHP'
<?php declare(strict_types = 1);

final class ScratchContainer extends \Nette\DI\Container
{

}

PHP);

		FileSystem::write($scratchDir . '/lib/ScratchCatalog.php', <<<'PHP'
<?php declare(strict_types = 1);

final class ScratchCatalog
{

}

PHP);

		FileSystem::write($scratchDir . '/loader.php', str_replace(
			['{{ROOT}}', '{{CONTAINER}}'],
			[$projectRoot, $bootstrapOnlyContainer ? '\\ScratchContainer' : '\\Nette\\DI\\Container'],
			<<<'PHP'
<?php declare(strict_types = 1);

require_once '{{ROOT}}/tests/autoload.php';

return new {{CONTAINER}}();

PHP,
		));

		$scenario->write('ScratchConsumer.php', <<<'PHP'
<?php declare(strict_types = 1);

final class ScratchConsumer
{

	public function lookup(\Nette\DI\Container $container): void
	{
		// The answer is read off the loader's container.
		$container->getService('missing');
	}

}

PHP);
	}

}
