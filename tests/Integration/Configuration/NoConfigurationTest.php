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

	public function testOutOfTheBoxPhpstanNetteShadowsFormsTyping(): void
	{
		$result = $this->project->analyse([], ConfigurationCorpus::PATHS);
		self::assertSame([], $result['errors'], $result['stderr']);

		self::assertSame(
			['src/HomePresenter.php:18 orisaiNette.component.unattachedParentAccess'],
			ConfigurationCorpus::findings($this->project, $result['messages'], 'orisaiNette.'),
		);
	}

	public function testDisabledAreasReportNothing(): void
	{
		$result = $this->project->analyse(
			ConfigurationCorpus::PHPSTAN_NETTE_SWITCHES + [
				'orisai' => ['nette' => [
					'forms' => ['enabled' => false],
					'component' => ['enabled' => false],
				]],
			],
			ConfigurationCorpus::PATHS,
		);
		self::assertSame([], $result['errors'], $result['stderr']);

		self::assertSame([], ConfigurationCorpus::findings($this->project, $result['messages'], 'orisaiNette.forms.'));
		self::assertSame(
			[],
			ConfigurationCorpus::findings($this->project, $result['messages'], 'orisaiNette.component.'),
		);
	}

	public function testLatte(): void
	{
		$result = $this->project->analyse(
			ConfigurationCorpus::PHPSTAN_NETTE_SWITCHES,
			[...ConfigurationCorpus::PATHS, 'src/tpl.latte'],
		);
		self::assertSame([], $result['errors'], $result['stderr']);

		self::assertSame([], ConfigurationCorpus::findings($this->project, $result['messages'], 'orisaiNette.latte.'));
	}

	public function testDic(): void
	{
		$result = $this->project->analyse(ConfigurationCorpus::PHPSTAN_NETTE_SWITCHES, ConfigurationCorpus::PATHS);
		self::assertSame([], $result['errors'], $result['stderr']);

		self::assertSame([], ConfigurationCorpus::findings($this->project, $result['messages'], 'orisaiNette.dic.'));
		self::assertSame(
			[
				'src/ContainerConsumer.php:12 Dumped type: Corpus\\Mailer',
				'src/ContainerConsumer.php:13 Dumped type: object',
			],
			ConfigurationCorpus::dumpedTypes($this->project, $result['messages']),
		);
	}

	public function testLatteForms(): void
	{
		$result = $this->project->analyse(
			ConfigurationCorpus::PHPSTAN_NETTE_SWITCHES,
			[...ConfigurationCorpus::PATHS, 'src/tpl.latte'],
		);
		self::assertSame([], $result['errors'], $result['stderr']);

		self::assertSame(
			[],
			ConfigurationCorpus::findings($this->project, $result['messages'], 'orisaiNette.latteForms.'),
		);
	}

}
