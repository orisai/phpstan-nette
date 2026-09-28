<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Forms\Invalidation;

use Tests\OriPhpstan\Nette\Integration\Configuration\ConfigurationCorpus;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\ScratchProject;
use function array_map;
use function basename;
use function glob;
use function sort;
use const GLOB_ONLYDIR;

// The shape store persists under content-only keys, but AnalysedPaths::isAnalysed() - which
// ContainerModel's vendor-method gates, the registrar convention check and the index containment
// gate branch on - reads the declared paths, a config value no content hash reflects. The store's
// version directory therefore carries the normalised paths: a universe change over a byte-identical
// corpus rotates it, a mere respelling of the same universe does not.
final class AnalysedPathsIdentityTest extends BaseTestCase
{

	private ScratchProject $project;

	protected function setUp(): void
	{
		parent::setUp();
		$this->project = ScratchProject::create('forms-analysed-paths');
		$this->project->write('src/Signup.php', <<<'PHP'
<?php declare(strict_types = 1);

namespace Corpus;

final class Signup extends \Nette\Application\UI\Control
{

	protected function createComponentForm(): \Nette\Application\UI\Form
	{
		$form = new \Nette\Application\UI\Form();
		$form->addText('email');

		return $form;
	}

	public function probe(): void
	{
		\PHPStan\dumpType($this['form']['email']);
	}

}

PHP);
		$this->project->write(
			'lib/Helper.php',
			"<?php declare(strict_types = 1);\n\nnamespace Lib;\n\nfinal class Helper\n{\n\n}\n",
		);
	}

	protected function tearDown(): void
	{
		$this->project->cleanup();
		parent::tearDown();
	}

	public function testDeclaredPathsAreTheStoreIdentity(): void
	{
		$narrow = $this->analyse(['src']);
		self::assertCount(1, $narrow);

		$widened = $this->analyse(['src', 'lib']);
		self::assertCount(1, $widened, 'the superseded version directory is pruned');
		self::assertNotSame($narrow, $widened);

		self::assertSame(
			$widened,
			$this->analyse(['lib', 'src', 'src']),
			'a respelling of the same universe shares the store',
		);
	}

	/**
	 * @param list<string> $paths
	 * @return list<string>
	 */
	private function analyse(array $paths): array
	{
		$result = $this->project->analyse(ConfigurationCorpus::PHPSTAN_NETTE_SWITCHES, $paths);
		self::assertSame([], $result['errors'], $result['stderr']);
		self::assertSame(
			['src/Signup.php:18 Dumped type: Nette\Forms\Controls\TextInput'],
			ConfigurationCorpus::dumpedTypes($this->project, $result['messages']),
		);

		$directories = glob($this->project->path('tmp/form-shape-cache') . '/v*', GLOB_ONLYDIR);
		self::assertNotFalse($directories);
		$names = array_map(static fn (string $directory): string => basename($directory), $directories);
		sort($names);

		return $names;
	}

}
