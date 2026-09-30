<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Latte3;

use Nette\Utils\FileSystem;
use Tests\OriPhpstan\Nette\Integration\Configuration\ConfigurationCorpus;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\ScratchProject;
use function basename;
use function dirname;

// A harvested engine with Feature::ScopedLoopVariables wraps every loop in a backup/restore shell;
// the analysis sees the plain loop, so neither {foreach} form reports anything but the body's own
// findings.
/**
 * @group latte31
 */
final class ScopedForeachSpawnTest extends BaseTestCase
{

	private ScratchProject $project;

	protected function setUp(): void
	{
		parent::setUp();
		$this->project = ScratchProject::create('scoped-foreach');
	}

	protected function tearDown(): void
	{
		$this->project->cleanup();
		parent::tearDown();
	}

	public function testScopedLoopsReportOnlyTheBodysFindings(): void
	{
		$this->project->write(
			'src/scoped.latte',
			FileSystem::read(dirname(__DIR__, 3) . '/Unit/Latte/Customs/Latte3/Fixtures/scoped-foreach.latte'),
		);
		$this->project->write(
			'src/body.latte',
			"{varType array<int, string> \$items}\n<p n:foreach=\"\$items as \$item\">{\$item->name}</p>\n"
			. "{foreach \$items as \$item}{\$item->name}{/foreach}\n",
		);

		$result = $this->project->analyse(
			ConfigurationCorpus::PHPSTAN_NETTE_SWITCHES + [
				'fileExtensions' => ['php', 'latte'],
				'orisai' => ['nette' => ['latte' => [
					'enabled' => true,
					'engineLoader' => dirname(
						__DIR__,
						3,
					) . '/Unit/Latte/Customs/Latte3/Fixtures/engine-loader-scoped-loops.php',
				]]],
			],
			['src'],
		);
		self::assertSame([], $result['errors'], $result['stderr']);

		$findings = [];
		foreach ($result['messages'] as $message) {
			$findings[] = basename($message['file']) . ':' . $message['line'] . ' ' . $message['identifier'];
		}

		self::assertSame(['body.latte:2 property.nonObject', 'body.latte:3 property.nonObject'], $findings);
	}

}
