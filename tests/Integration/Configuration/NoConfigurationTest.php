<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Configuration;

use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\ScratchProject;

final class NoConfigurationTest extends BaseTestCase
{

	private ScratchProject $project;

	protected function setUp(): void
	{
		parent::setUp();
		$this->project = ScratchProject::create('no-configuration');
	}

	protected function tearDown(): void
	{
		$this->project->cleanup();
		parent::tearDown();
	}

	public function testDefaults(): void
	{
		ConfigurationCorpus::write($this->project);

		$first = $this->project->analyse([], ConfigurationCorpus::PATHS);
		self::assertSame([], $first['errors'], $first['stderr']);

		$second = $this->project->analyse([], ConfigurationCorpus::PATHS);
		self::assertSame($first['messages'], $second['messages']);

		self::markTestIncomplete('areas arrive in Tasks 7-10');
	}

}
