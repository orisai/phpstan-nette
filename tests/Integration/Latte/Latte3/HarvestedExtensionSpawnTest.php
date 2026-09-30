<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Latte3;

use Nette\Utils\FileSystem;
use Tests\OriPhpstan\Nette\Integration\Configuration\ConfigurationCorpus;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\ScratchProject;
use Tests\OriPhpstan\Nette\Unit\Latte\Customs\Latte3\Fixtures\FixtureExtension;
use function dirname;

// A Latte 3 engine loader's extension end to end: its tag compiles natively, its filter and
// functions are checked against their real signatures, and a Template-aware function keeps the
// template the runtime passes it.
/**
 * @group latte3
 */
final class HarvestedExtensionSpawnTest extends BaseTestCase
{

	private ScratchProject $project;

	protected function setUp(): void
	{
		parent::setUp();
		$this->project = ScratchProject::create('harvested-extension');
	}

	protected function tearDown(): void
	{
		$this->project->cleanup();
		parent::tearDown();
	}

	public function testHarvestedCustomsAreCheckedAgainstTheirSignatures(): void
	{
		$this->project->write('src/customs.latte', FileSystem::read(__DIR__ . '/Fixtures/harvest/customs.latte'));

		self::assertSame(
			[
				'4 argument.type Parameter #1 $s of method ' . FixtureExtension::class . '::shout() expects string, array<int> given.',
				'6 arguments.count Method ' . FixtureExtension::class . '::twice() invoked with 0 parameters, 1 required.',
				'8 arguments.count Method ' . FixtureExtension::class . '::greet() invoked with 1 parameter, 2 required.',
			],
			$this->analyse(dirname(__DIR__, 3) . '/Unit/Latte/Customs/Latte3/Fixtures/engine-loader.php'),
		);
	}

	public function testUiFunctionsAreKnownWithoutAHarvest(): void
	{
		$this->project->write(
			'src/ui.latte',
			"{if isLinkCurrent('Foo:bar')}a{/if}\n{if isModuleCurrent('Admin')}b{/if}\n",
		);

		self::assertSame([], $this->analyse(null));
	}

	/**
	 * @return list<string>
	 */
	private function analyse(?string $engineLoader): array
	{
		$result = $this->project->analyse(
			ConfigurationCorpus::PHPSTAN_NETTE_SWITCHES + [
				'fileExtensions' => ['php', 'latte'],
				'orisaiNette' => ['latte' => ['enabled' => true, 'engineLoader' => $engineLoader]],
			],
			['src'],
		);
		self::assertSame([], $result['errors'], $result['stderr']);

		$findings = [];
		foreach ($result['messages'] as $message) {
			$findings[] = $message['line'] . ' ' . $message['identifier'] . ' ' . $message['message'];
		}

		return $findings;
	}

}
