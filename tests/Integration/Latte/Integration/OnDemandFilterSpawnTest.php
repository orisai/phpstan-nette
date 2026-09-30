<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration;

use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use Tests\OriPhpstan\Nette\Integration\Configuration\ConfigurationCorpus;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\ScratchProject;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;
use Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures\FixtureLoaderFilters;

// A filter the engine's filter loader answers is checked against the callable it answers with, on
// every Latte line; a name the loader declines stays unknown. Loaders are asked with the name as
// written (formatDyn is a camelCase map lookup). Latte 2 files an answer under the lowercase name,
// so dYn after dyn works there at runtime (the lowercase fallback); Latte 3 is case-sensitive.
final class OnDemandFilterSpawnTest extends BaseTestCase
{

	private const ENGINE_LOADER = __DIR__ . '/../../../Unit/Latte/Customs/Fixtures/engine-loader-filter-loader.php';

	private ScratchProject $project;

	protected function setUp(): void
	{
		parent::setUp();
		$this->project = ScratchProject::create('on-demand-filters');
	}

	protected function tearDown(): void
	{
		$this->project->cleanup();
		parent::tearDown();
	}

	public function testLoaderFilterIsTypedAndDeclinedNamesStayUnknown(): void
	{
		$this->project->write(
			'src/filters.latte',
			"{varType string \$s}\n{varType int \$i}\n{\$s|dyn}\n{\$i|dyn}\n{\$s|nope}\n{\$s|dYn}\n{\$s|formatDyn}\n",
		);

		$expected = [
			'4 argument.type Parameter #1 $s of static method ' . FixtureLoaderFilters::class
				. '::dyn() expects string, int given.',
			"5 orisaiNette.latte.unknownFilter Unknown Latte filter 'nope'.",
		];
		if (TestAdapter::factory()->family()->latteLine !== ShapeFamily::LATTE_2) {
			$expected[] = "6 orisaiNette.latte.unknownFilter Unknown Latte filter 'dYn'.";
		}

		self::assertSame($expected, $this->analyse(self::ENGINE_LOADER));
	}

	public function testWithoutAnEngineLoaderTheNameIsUnknown(): void
	{
		$this->project->write('src/filters.latte', "{varType string \$s}\n{\$s|dyn}\n");

		self::assertSame(["2 orisaiNette.latte.unknownFilter Unknown Latte filter 'dyn'."], $this->analyse(null));
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
