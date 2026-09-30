<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Latte3;

use Nette\Utils\FileSystem;
use Nette\Utils\Finder;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use function dirname;
use function sort;

// The Latte 3 compile joins LatteAnalysisCache under the harvest salt: an edit to a harvested
// extension's file - same class, same tag names - must recompile the template it drives, while a
// re-analysis of an unchanged project reuses the cached compile.
/**
 * @group latte3
 */
final class HarvestInvalidationTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/../Integration/Fixtures/integration.neon';

	public function testExtensionFileEditRecompilesTheTemplate(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scenario = InvalidationScenario::create(
			$projectRoot,
			'latte3-harvest-inval',
			static fn (array $paths, string $tmpDir): string => LattePhpstanConfig::create(
				self::REAL_CONFIG_PATH,
				$paths,
				$tmpDir,
				['orisaiNette.latte.engineLoader' => dirname($paths[0]) . '/engine-loader.php'],
			)->getConfigPath(),
		);

		try {
			$this->writeExtension($scenario, "'echo 1;'");
			FileSystem::write(
				$scenario->getScratchDir() . '/engine-loader.php',
				"<?php declare(strict_types = 1);\n\n"
				. "require_once '$projectRoot/tests/autoload.php';\n"
				. "require_once __DIR__ . '/ext/ScratchHelloExtension.php';\n\n"
				. "\$engine = new Latte\\Engine();\n"
				. "\$engine->addExtension(new ScratchHelloExtension());\n\n"
				. "return \$engine;\n",
			);
			$scenario->write('hello.latte', "{hello}\n");

			self::assertSame('(no errors)', $scenario->settle()->getErrorText());

			$entries = $this->cacheEntries($scenario);
			self::assertNotSame([], $entries);
			self::assertSame('(no errors)', $scenario->cold()->getErrorText());
			self::assertSame(
				$entries,
				$this->cacheEntries($scenario),
				'an unchanged harvest reuses the cached compile',
			);

			$this->writeExtension($scenario, "'echo \$undefinedHello;'");

			$warm = $scenario->settle();
			self::assertSame(
				'hello.latte:1 :: variable.undefined :: Undefined variable: $undefinedHello',
				$warm->getErrorText(),
			);
			self::assertSame($warm->getErrorText(), $scenario->cold()->getErrorText());
		} finally {
			$scenario->cleanup();
		}
	}

	private function writeExtension(InvalidationScenario $scenario, string $code): void
	{
		FileSystem::write(
			$scenario->getScratchDir() . '/ext/ScratchHelloExtension.php',
			"<?php declare(strict_types = 1);\n\n"
			. "final class ScratchHelloExtension extends Latte\\Extension\n{\n\n"
			. "\tpublic function getTags(): array\n\t{\n"
			. "\t\treturn ['hello' => static fn (): Latte\\Compiler\\Nodes\\AuxiliaryNode"
			. " => new Latte\\Compiler\\Nodes\\AuxiliaryNode(static fn (): string => $code)];\n"
			. "\t}\n\n}\n",
		);
	}

	/**
	 * @return list<string>
	 */
	private function cacheEntries(InvalidationScenario $scenario): array
	{
		$entries = [];
		$directory = $scenario->getScratchDir() . '/pstmp/latte-analysis-cache';
		foreach (Finder::findFiles('*.ser')->from($directory) as $file) {
			$entries[] = $file->getFilename();
		}

		sort($entries);

		return $entries;
	}

}
