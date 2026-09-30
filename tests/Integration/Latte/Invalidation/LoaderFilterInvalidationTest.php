<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Invalidation;

use Nette\Utils\FileSystem;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use function dirname;

// What the engine's filter loaders answer for a template's filter names is part of the harvest
// salt: the loader file sits outside the analysed set, so only the salt can carry a loader that
// starts or stops answering a name into both caches, on every Latte line.
final class LoaderFilterInvalidationTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/../Integration/Fixtures/integration.neon';

	private const UNKNOWN_ERROR = "filters.latte:2 :: orisaiNette.latte.unknownFilter :: Unknown Latte filter 'dyn'.";

	private const ANSWERS_DYN = "\$name === 'dyn' ? ['ScratchLoaderFilters', 'dyn'] : null";

	private const ANSWERS_NOTHING = 'null';

	public function testLoaderThatStopsAnsweringReportsTheFilter(): void
	{
		$this->assertLoaderEdit(self::ANSWERS_DYN, '(no errors)', self::ANSWERS_NOTHING, self::UNKNOWN_ERROR);
	}

	public function testLoaderThatStartsAnsweringClearsTheFinding(): void
	{
		$this->assertLoaderEdit(self::ANSWERS_NOTHING, self::UNKNOWN_ERROR, self::ANSWERS_DYN, '(no errors)');
	}

	private function assertLoaderEdit(string $before, string $beforeErrors, string $after, string $afterErrors): void
	{
		$scenario = $this->scenario();

		try {
			$this->writeEngineLoader($scenario, $before);
			$scenario->write('filters.latte', "{varType string \$s}\n{\$s|dyn}\n");

			self::assertSame($beforeErrors, $scenario->settle()->getErrorText());
			$warm = $scenario->run();
			self::assertTrue($warm->isCacheRestored(), $warm->getDiagnostics());
			self::assertSame($beforeErrors, $warm->getErrorText());

			$this->writeEngineLoader($scenario, $after);

			$settled = $scenario->settle();
			self::assertSame($afterErrors, $settled->getErrorText());
			self::assertSame($afterErrors, $scenario->cold()->getErrorText());
		} finally {
			$scenario->cleanup();
		}
	}

	private function scenario(): InvalidationScenario
	{
		$projectRoot = dirname(__DIR__, 4);
		$scenario = InvalidationScenario::create(
			$projectRoot,
			'latte-loader-filter-inval',
			static fn (array $paths, string $tmpDir): string => LattePhpstanConfig::create(
				self::REAL_CONFIG_PATH,
				$paths,
				$tmpDir,
				['orisaiNette.latte.engineLoader' => dirname($paths[0]) . '/engine-loader.php'],
			)->getConfigPath(),
		);

		FileSystem::write(
			$scenario->getScratchDir() . '/ScratchLoaderFilters.php',
			"<?php declare(strict_types = 1);\n\n"
			. "final class ScratchLoaderFilters\n{\n\n"
			. "\tpublic static function dyn(string \$s): string\n\t{\n\t\treturn \$s;\n\t}\n\n}\n",
		);

		return $scenario;
	}

	private function writeEngineLoader(InvalidationScenario $scenario, string $answer): void
	{
		$projectRoot = dirname(__DIR__, 4);
		FileSystem::write(
			$scenario->getScratchDir() . '/engine-loader.php',
			"<?php declare(strict_types = 1);\n\n"
			. "require_once '$projectRoot/tests/autoload.php';\n"
			. "require_once __DIR__ . '/ScratchLoaderFilters.php';\n\n"
			. "\$engine = new Latte\\Engine();\n"
			. "\$engine->addFilterLoader(static fn (string \$name): ?array => $answer);\n\n"
			. "return \$engine;\n",
		);
	}

}
