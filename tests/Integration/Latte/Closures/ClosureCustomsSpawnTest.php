<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Closures;

use Nette\Utils\FileSystem;
use Tests\OriPhpstan\Nette\Integration\Configuration\ConfigurationCorpus;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\ScratchProject;

// A filter or function registered as an anonymous closure has no declaration to type it by: on every
// Latte line it is known, untyped and never argument-checked, while an unregistered name stays unknown.
final class ClosureCustomsSpawnTest extends BaseTestCase
{

	private ScratchProject $project;

	protected function setUp(): void
	{
		parent::setUp();
		$this->project = ScratchProject::create('closure-customs');
	}

	protected function tearDown(): void
	{
		$this->project->cleanup();
		parent::tearDown();
	}

	public function testClosureCustomsAreKnownButUntyped(): void
	{
		$this->project->write(
			'src/closures.latte',
			FileSystem::read(__DIR__ . '/Fixtures/closures.latte'),
		);

		$result = $this->project->analyse(
			ConfigurationCorpus::PHPSTAN_NETTE_SWITCHES + [
				'fileExtensions' => ['php', 'latte'],
				'orisai' => ['nette' => ['latte' => [
					'enabled' => true,
					'engineLoader' => __DIR__ . '/Fixtures/engine-loader.php',
				]]],
			],
			['src'],
		);
		self::assertSame([], $result['errors'], $result['stderr']);

		$findings = [];
		foreach ($result['messages'] as $message) {
			$findings[] = $message['line'] . ' ' . $message['identifier'] . ' ' . $message['message'];
		}

		self::assertSame(["6 orisaiNette.latte.unknownFilter Unknown Latte filter 'nope'."], $findings);
	}

}
