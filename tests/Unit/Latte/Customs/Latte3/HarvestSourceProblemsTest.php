<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Customs\Latte3;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Compile\Diagnostic;
use OriPhpstan\Nette\Latte\Customs\CustomsHarvester;
use OriPhpstan\Nette\Latte\Customs\EngineSource;
use OriPhpstan\Nette\Latte\Customs\ExtensionSourceSalt;
use OriPhpstan\Nette\Latte\Customs\HarvestProblemReporter;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Support\ProjectInstalledVersions;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;
use function array_map;
use function chmod;
use function crc32;
use function dirname;
use function function_exists;
use function posix_geteuid;
use function uniqid;
use const DIRECTORY_SEPARATOR;

// The salt walk's findings travel with the harvest: an unreadable directory keeps the extensions and
// becomes one diagnostic on the universe's first template; a project-root extension a dump note.
/**
 * @group latte3
 */
final class HarvestSourceProblemsTest extends BaseTestCase
{

	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 5) . '/var/tmp/harvest-source-problems-' . uniqid();
	}

	protected function tearDown(): void
	{
		@chmod($this->root . '/ext/Locked', 0755);
		FileSystem::delete($this->root);
		parent::tearDown();
	}

	public function testUnreadableDirectoryKeepsTheHarvestAndReportsOnce(): void
	{
		if (DIRECTORY_SEPARATOR === '\\' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
			self::markTestSkipped('Needs a filesystem that can deny a directory to this user.');
		}

		$harvester = $this->harvester(null);
		FileSystem::write($this->root . '/ext/Locked/Node.php', '<?php');
		chmod($this->root . '/ext/Locked', 0000);

		$harvested = $harvester->harvest();
		self::assertContains('scratchHello', $harvested->getMacroNames());
		self::assertSame(
			['directory ' . $this->root . '/ext/Locked under extension ' . $this->extensionClass() . ' is unreadable'],
			$harvested->getSourceProblems(),
		);

		FileSystem::write($this->root . '/src/a.latte', '');
		FileSystem::write($this->root . '/src/b.latte', '');
		$reporter = new HarvestProblemReporter($harvester, new LatteUniverse([$this->root . '/src'], $this->root));

		self::assertSame([], $reporter->diagnosticsFor('src/b.latte'));
		self::assertSame(
			[HarvestProblemReporter::IDENTIFIER . ' 1'],
			array_map(
				static fn (Diagnostic $diagnostic): string => $diagnostic->getIdentifier() . ' ' . $diagnostic->getLatteLine(),
				$reporter->diagnosticsFor('src/a.latte'),
			),
		);
	}

	public function testProjectRootExtensionIsNotedAsShallow(): void
	{
		$harvested = $this->harvester($this->root . '/ext')->harvest();

		self::assertSame([], $harvested->getSourceProblems());
		self::assertSame(
			[
				'extension ' . $this->extensionClass() . ' is salted shallowly (only its own directory\'s PHP files)'
				. ' - declare extensions in a directory of their own, below the project root',
			],
			$harvested->getSourceNotes(),
		);
	}

	private function harvester(?string $projectRoot): CustomsHarvester
	{
		$class = $this->extensionClass();
		FileSystem::write(
			$this->root . '/ext/ScratchExtension.php',
			"<?php declare(strict_types = 1);\n\nfinal class $class extends Latte\\Extension\n{\n\n"
			. "\tpublic function getTags(): array\n\t{\n"
			. "\t\treturn ['scratchHello' => static fn () => new Latte\\Compiler\\Nodes\\NopNode()];\n"
			. "\t}\n\n}\n",
		);
		FileSystem::write(
			$this->root . '/engine-loader.php',
			"<?php declare(strict_types = 1);\n\nrequire_once __DIR__ . '/ext/ScratchExtension.php';\n\n"
			. "\$engine = new Latte\\Engine();\n\$engine->addExtension(new $class());\n\nreturn \$engine;\n",
		);

		return new CustomsHarvester(
			new EngineSource(null, $this->root . '/engine-loader.php'),
			TestAdapter::factory(),
			new ExtensionSourceSalt(null, $projectRoot, ProjectInstalledVersions::get()),
		);
	}

	private function extensionClass(): string
	{
		return 'ScratchHarvestExtension' . (string) crc32($this->root);
	}

}
