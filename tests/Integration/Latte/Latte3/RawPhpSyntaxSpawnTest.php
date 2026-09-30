<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Latte3;

use Tests\OriPhpstan\Nette\Integration\Configuration\ConfigurationCorpus;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\ScratchProject;
use function strlen;
use function substr;

// {php} prints its code verbatim: a malformed one is the template's compile error, and the rest of
// the run keeps its findings.
/**
 * @group latte3
 */
final class RawPhpSyntaxSpawnTest extends BaseTestCase
{

	private ScratchProject $project;

	protected function setUp(): void
	{
		parent::setUp();
		$this->project = ScratchProject::create('raw-php-syntax');
	}

	protected function tearDown(): void
	{
		$this->project->cleanup();
		parent::tearDown();
	}

	public function testUnparsableRawPhpIsAParseErrorOnItsLine(): void
	{
		$this->project->write('src/broken.latte', "a\n\n{php \$a = }\n");
		$this->project->write('src/other.latte', "{\$undefined}\n");

		$result = $this->project->analyse(
			ConfigurationCorpus::PHPSTAN_NETTE_SWITCHES + [
				'fileExtensions' => ['php', 'latte'],
				'orisai' => ['nette' => [
					'latte' => [
						'enabled' => true,
						'engineLoader' => __DIR__ . '/Fixtures/raw-php/engine-loader.php',
					],
				]],
			],
			['src'],
		);
		self::assertSame([], $result['errors'], $result['stderr']);

		$findings = [];
		foreach ($result['messages'] as $message) {
			$findings[] = substr($message['file'], strlen($this->project->path(''))) . ':' . $message['line'] . ' '
				. $message['identifier'] . ' ' . $message['message'];
		}

		self::assertSame(
			[
				"src/broken.latte:3 orisaiNette.latte.parseError Error in template: Syntax error, unexpected ';'",
				'src/other.latte:1 variable.undefined Undefined variable: $undefined',
			],
			$findings,
		);
	}

}
