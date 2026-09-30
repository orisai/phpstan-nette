<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Latte3;

use Nette\Utils\FileSystem;
use Nette\Utils\Finder;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use function dirname;
use function sort;

// The Latte 3 compile joins LatteAnalysisCache under the harvest salt: an edit to a first-party
// extension's file or to any PHP file under its directory (its node classes) - same classes, same
// tag names - must recompile the template it drives, while an edit outside that tree and a
// re-analysis of an unchanged project reuse the cached compile.
/**
 * @group latte3
 */
final class HarvestInvalidationTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/../Integration/Fixtures/integration.neon';

	private const UNDEFINED_ERROR = 'hello.latte:1 :: variable.undefined :: Undefined variable: $undefinedHello';

	public function testExtensionFileEditRecompilesTheTemplate(): void
	{
		$this->assertEditRecompiles(function (InvalidationScenario $scenario): void {
			$this->writeExtension(
				$scenario,
				"new Latte\\Compiler\\Nodes\\AuxiliaryNode(static fn (): string => 'echo \$undefinedHello;')",
			);
		});
	}

	public function testNodeFileEditRecompilesTheTemplate(): void
	{
		$this->assertEditRecompiles(function (InvalidationScenario $scenario): void {
			$this->writeNode($scenario, "'echo \$undefinedHello;'");
		});
	}

	public function testEditOutsideTheExtensionTreeReusesTheCompile(): void
	{
		$scenario = $this->seededScenario();

		try {
			$entries = $this->cacheEntries($scenario);

			FileSystem::write(
				$scenario->getScratchDir() . '/other/Unrelated.php',
				"<?php declare(strict_types = 1);\n\nfinal class ScratchUnrelated\n{\n\n\tpublic const X = 2;\n\n}\n",
			);

			$warm = $scenario->run();
			self::assertTrue($warm->isCacheRestored(), $warm->getDiagnostics());
			self::assertSame('(no errors)', $warm->getErrorText());
			self::assertSame('(no errors)', $scenario->cold()->getErrorText());
			self::assertSame($entries, $this->cacheEntries($scenario));
		} finally {
			$scenario->cleanup();
		}
	}

	/**
	 * @param callable(InvalidationScenario): void $edit
	 */
	private function assertEditRecompiles(callable $edit): void
	{
		$scenario = $this->seededScenario();

		try {
			$entries = $this->cacheEntries($scenario);
			self::assertNotSame([], $entries);
			self::assertSame('(no errors)', $scenario->cold()->getErrorText());
			self::assertSame(
				$entries,
				$this->cacheEntries($scenario),
				'an unchanged harvest reuses the cached compile',
			);

			$edit($scenario);

			$warm = $scenario->settle();
			self::assertSame(self::UNDEFINED_ERROR, $warm->getErrorText());
			self::assertSame($warm->getErrorText(), $scenario->cold()->getErrorText());
		} finally {
			$scenario->cleanup();
		}
	}

	private function seededScenario(): InvalidationScenario
	{
		$projectRoot = dirname(__DIR__, 4);
		$scenario = InvalidationScenario::create(
			$projectRoot,
			'latte3-harvest-inval',
			static fn (array $paths, string $tmpDir): string => LattePhpstanConfig::create(
				self::REAL_CONFIG_PATH,
				$paths,
				$tmpDir,
				['orisai.nette.latte.engineLoader' => dirname($paths[0]) . '/engine-loader.php'],
			)->getConfigPath(),
		);

		$this->writeExtension($scenario, 'new ScratchHelloNode()');
		$this->writeNode($scenario, "'echo 1;'");
		FileSystem::write(
			$scenario->getScratchDir() . '/other/Unrelated.php',
			"<?php declare(strict_types = 1);\n\nfinal class ScratchUnrelated\n{\n\n\tpublic const X = 1;\n\n}\n",
		);
		FileSystem::write(
			$scenario->getScratchDir() . '/engine-loader.php',
			"<?php declare(strict_types = 1);\n\n"
			. "require_once '$projectRoot/tests/autoload.php';\n"
			. "require_once __DIR__ . '/ext/Nodes/ScratchHelloNode.php';\n"
			. "require_once __DIR__ . '/ext/ScratchHelloExtension.php';\n\n"
			. "\$engine = new Latte\\Engine();\n"
			. "\$engine->addExtension(new ScratchHelloExtension());\n\n"
			. "return \$engine;\n",
		);
		$scenario->write('hello.latte', "{hello}\n");

		self::assertSame('(no errors)', $scenario->settle()->getErrorText());

		return $scenario;
	}

	private function writeExtension(InvalidationScenario $scenario, string $node): void
	{
		FileSystem::write(
			$scenario->getScratchDir() . '/ext/ScratchHelloExtension.php',
			"<?php declare(strict_types = 1);\n\n"
			. "final class ScratchHelloExtension extends Latte\\Extension\n{\n\n"
			. "\tpublic function getTags(): array\n\t{\n"
			. "\t\treturn ['hello' => static fn (): Latte\\Compiler\\Node => $node];\n"
			. "\t}\n\n}\n",
		);
	}

	private function writeNode(InvalidationScenario $scenario, string $code): void
	{
		FileSystem::write(
			$scenario->getScratchDir() . '/ext/Nodes/ScratchHelloNode.php',
			"<?php declare(strict_types = 1);\n\n"
			. "final class ScratchHelloNode extends Latte\\Compiler\\Nodes\\StatementNode\n{\n\n"
			. "\tpublic function print(Latte\\Compiler\\PrintContext \$context): string\n\t{\n"
			. "\t\treturn $code;\n"
			. "\t}\n\n"
			. "\tpublic function &getIterator(): Generator\n\t{\n"
			. "\t\tfalse && yield;\n"
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
