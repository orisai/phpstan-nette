<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Configuration;

use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\ScratchProject;

final class MinimalConfigurationTest extends BaseTestCase
{

	private ScratchProject $project;

	protected function setUp(): void
	{
		parent::setUp();
		$this->project = ScratchProject::create('minimal-configuration');
	}

	protected function tearDown(): void
	{
		$this->project->cleanup();
		parent::tearDown();
	}

	public function testQuickStart(): void
	{
		ConfigurationCorpus::write($this->project);

		$result = $this->project->analyse($this->quickStartParameters(), ConfigurationCorpus::PATHS);
		self::assertSame([], $result['errors'], $result['stderr']);

		self::markTestIncomplete('areas arrive in Tasks 7-10');
	}

	/**
	 * @return array<string, mixed>
	 */
	private function quickStartParameters(): array
	{
		return [
			'fileExtensions' => ['php', 'latte'],
			'orisaiNette' => [
				'latte' => ['enabled' => true],
				'dic' => ['containerLoader' => $this->project->path('container-loader.php')],
			],
		];
	}

}
