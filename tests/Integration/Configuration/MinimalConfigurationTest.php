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
		ConfigurationCorpus::write($this->project);
	}

	protected function tearDown(): void
	{
		$this->project->cleanup();
		parent::tearDown();
	}

	public function testQuickStartIsAccepted(): void
	{
		$result = $this->project->analyse($this->quickStartParameters(), ConfigurationCorpus::PATHS);
		self::assertSame([], $result['errors'], $result['stderr']);
	}

	public function testFormsAndComponent(): void
	{
		$result = $this->project->analyse($this->quickStartParameters(), ConfigurationCorpus::PATHS);
		self::assertSame([], $result['errors'], $result['stderr']);

		self::assertSame(
			[
				'src/HomePresenter.php:18 orisaiNette.component.unattachedParentAccess',
				'src/ProfileControl.php:28 orisaiNette.forms.noSuchComponent',
			],
			[
				...ConfigurationCorpus::findings($this->project, $result['messages'], 'orisaiNette.component.'),
				...ConfigurationCorpus::findings($this->project, $result['messages'], 'orisaiNette.forms.'),
			],
		);
	}

	public function testLatte(): void
	{
		self::markTestIncomplete('Latte arrives in Task 9: template findings appear');
	}

	public function testDic(): void
	{
		$result = $this->project->analyse($this->quickStartParameters(), ConfigurationCorpus::PATHS);
		self::assertSame([], $result['errors'], $result['stderr']);

		self::assertSame(
			[
				'src/ContainerConsumer.php:12 Dumped type: Corpus\\Mailer',
				'src/ContainerConsumer.php:13 Dumped type: Corpus\\Mailer',
			],
			ConfigurationCorpus::dumpedTypes($this->project, $result['messages']),
		);
		self::assertSame(
			['src/ContainerConsumer.php:18 orisaiNette.dic.serviceNotFound'],
			ConfigurationCorpus::findings($this->project, $result['messages'], 'orisaiNette.dic.'),
		);
	}

	public function testLatteForms(): void
	{
		self::markTestIncomplete(
			'The Latte-Forms bridge arrives in Task 10: {input nope} is reported without a bridge flag',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function quickStartParameters(): array
	{
		return ConfigurationCorpus::PHPSTAN_NETTE_SWITCHES + [
			'fileExtensions' => ['php', 'latte'],
			'orisaiNette' => [
				'latte' => ['enabled' => true],
				'dic' => ['containerLoader' => $this->project->path('container-loader.php')],
			],
		];
	}

}
