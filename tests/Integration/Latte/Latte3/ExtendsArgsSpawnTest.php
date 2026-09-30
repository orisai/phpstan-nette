<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Latte3;

use Nette\Utils\FileSystem;
use Tests\OriPhpstan\Nette\Integration\Configuration\ConfigurationCorpus;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\ScratchProject;
use function basename;
use function sort;

// Latte 3.1's `{extends file, args}` passes the args to the parent (`$this->parentArgs + $params`):
// the include contract of the parent is checked against them like an {include}'s own args.
/**
 * @group latte31
 */
final class ExtendsArgsSpawnTest extends BaseTestCase
{

	private ScratchProject $project;

	protected function setUp(): void
	{
		parent::setUp();
		$this->project = ScratchProject::create('extends-args');
	}

	protected function tearDown(): void
	{
		$this->project->cleanup();
		parent::tearDown();
	}

	public function testExtendsArgsSatisfyAndMismatchTheParentsDeclaredVars(): void
	{
		foreach (['page', 'child', 'child-bad', 'child-none'] as $name) {
			$this->project->write(
				"src/$name.latte",
				FileSystem::read(__DIR__ . "/Fixtures/extends-args/$name.latte"),
			);
		}

		$result = $this->project->analyse(
			ConfigurationCorpus::PHPSTAN_NETTE_SWITCHES + [
				'fileExtensions' => ['php', 'latte'],
				'orisai' => ['nette' => ['latte' => ['enabled' => true]]],
			],
			['src'],
		);
		self::assertSame([], $result['errors'], $result['stderr']);

		$findings = [];
		foreach ($result['messages'] as $message) {
			$findings[] = basename($message['file']) . ':' . $message['line'] . ' ' . $message['identifier'] . ' '
				. $message['message'];
		}

		sort($findings);

		self::assertSame([
			"child-bad.latte:1 orisai.nette.latte.includeTypeMismatch Variable \$n provided as string does not match declared type int in 'src/page.latte'.",
			"child-bad.latte:1 orisai.nette.latte.includeTypeMismatch Variable \$x provided as int does not match declared type string in 'src/page.latte'.",
			"child-none.latte:1 orisai.nette.latte.includeMissingVariable Include target 'src/page.latte' requires variable \$n (int) that is not provided and has no default.",
			"child-none.latte:1 orisai.nette.latte.includeMissingVariable Include target 'src/page.latte' requires variable \$x (string) that is not provided and has no default.",
			"child.latte:1 orisai.nette.latte.includeMissingVariable Include target 'src/page.latte' requires variable \$n (int) that is not provided and has no default.",
		], $findings);
	}

}
