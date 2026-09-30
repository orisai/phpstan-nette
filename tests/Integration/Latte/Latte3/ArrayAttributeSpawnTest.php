<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Latte3;

use Nette\Utils\FileSystem;
use Tests\OriPhpstan\Nette\Integration\Configuration\ConfigurationCorpus;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\ScratchProject;

// Latte 3.1 prints an attribute whole and accepts arrays (and objects for data-*) in the list,
// style, data, json and aria formatters: the analysis must not turn those into an echo of an array.
/**
 * @group latte31
 */
final class ArrayAttributeSpawnTest extends BaseTestCase
{

	private ScratchProject $project;

	protected function setUp(): void
	{
		parent::setUp();
		$this->project = ScratchProject::create('array-attributes');
	}

	protected function tearDown(): void
	{
		$this->project->cleanup();
		parent::tearDown();
	}

	public function testArrayValuedAttributesAreNotEchoedAsArrays(): void
	{
		$this->project->write('src/attributes.latte', FileSystem::read(__DIR__ . '/Fixtures/array-attributes.latte'));

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
			$findings[] = $message['line'] . ' ' . $message['identifier'] . ' ' . $message['message'];
		}

		self::assertSame([], $findings);
	}

}
