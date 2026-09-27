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
		ConfigurationCorpus::write($this->project);
	}

	protected function tearDown(): void
	{
		$this->project->cleanup();
		parent::tearDown();
	}

	public function testDefaultsAreDeterministic(): void
	{
		$first = $this->project->analyse(ConfigurationCorpus::PHPSTAN_NETTE_SWITCHES, ConfigurationCorpus::PATHS);
		self::assertSame([], $first['errors'], $first['stderr']);

		$second = $this->project->analyse(ConfigurationCorpus::PHPSTAN_NETTE_SWITCHES, ConfigurationCorpus::PATHS);
		self::assertSame($first['messages'], $second['messages']);
	}

	public function testFormsAndComponent(): void
	{
		$result = $this->project->analyse(ConfigurationCorpus::PHPSTAN_NETTE_SWITCHES, ConfigurationCorpus::PATHS);
		self::assertSame([], $result['errors'], $result['stderr']);

		self::assertSame(
			[
				'src/HomePresenter.php:18 orisaiNette.component.unattachedParentAccess',
				'src/ProfileControl.php:28 orisaiNette.forms.noSuchComponent',
			],
			ConfigurationCorpus::findings($this->project, $result['messages'], 'orisaiNette.'),
		);
	}

	public function testLatte(): void
	{
		self::markTestIncomplete('Latte arrives in Task 9: the template stays unanalysed without configuration');
	}

	public function testDic(): void
	{
		self::markTestIncomplete('Dic arrives in Task 8: no container typing without a loader');
	}

	public function testLatteForms(): void
	{
		self::markTestIncomplete(
			'The Latte-Forms bridge arrives in Task 10: {input nope} stays silent while Latte is off',
		);
	}

}
