<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Configuration;

use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\ScratchProject;

/**
 * @group latte2
 */
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
		$result = $this->project->analyse($this->quickStartParameters(), ConfigurationCorpus::PATHS);
		self::assertSame([], $result['errors'], $result['stderr']);

		self::assertSame(
			['src/tpl.latte:2 orisaiNette.latte.unknownFilter'],
			ConfigurationCorpus::findings($this->project, $result['messages'], 'orisaiNette.latte.'),
		);
	}

	public function testLatteWithoutDiscovery(): void
	{
		$parameters = $this->quickStartParameters();
		$parameters['orisaiNette']['latte']['discovery'] = ['enabled' => false];
		$result = $this->project->analyse($parameters, ConfigurationCorpus::PATHS);
		self::assertSame([], $result['errors'], $result['stderr']);

		self::assertSame(
			['src/tpl.latte:2 orisaiNette.latte.unknownFilter'],
			ConfigurationCorpus::findings($this->project, $result['messages'], 'orisaiNette.latte.'),
		);
	}

	// The corpus container has no presenter mapping, so with a mapping source configured the
	// presenter's template discovery is opaque - and switching discovery off silences exactly that.
	public function testDiscoveryOptOutSilencesOpaqueDiscovery(): void
	{
		$parameters = $this->quickStartParameters();
		$parameters['orisaiNette']['latte']['templateFactoryContainerLoader'] = $this->project->path(
			'container-loader.php',
		);

		$enabled = $this->project->analyse($parameters, ConfigurationCorpus::PATHS);
		self::assertSame([], $enabled['errors'], $enabled['stderr']);
		self::assertSame(
			[
				'src/HomePresenter.php:7 orisaiNette.latte.fileDiscoveryOpaque',
				'src/tpl.latte:2 orisaiNette.latte.unknownFilter',
			],
			ConfigurationCorpus::findings($this->project, $enabled['messages'], 'orisaiNette.latte.'),
		);

		$parameters['orisaiNette']['latte']['discovery'] = ['enabled' => false];
		$disabled = $this->project->analyse($parameters, ConfigurationCorpus::PATHS);
		self::assertSame([], $disabled['errors'], $disabled['stderr']);
		self::assertSame(
			['src/tpl.latte:2 orisaiNette.latte.unknownFilter'],
			ConfigurationCorpus::findings($this->project, $disabled['messages'], 'orisaiNette.latte.'),
		);
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
		$result = $this->project->analyse($this->quickStartParameters(), ConfigurationCorpus::PATHS);
		self::assertSame([], $result['errors'], $result['stderr']);

		self::assertSame(
			['src/tpl.latte:1 orisaiNette.latteForms.unknownControl'],
			ConfigurationCorpus::findings($this->project, $result['messages'], 'orisaiNette.latteForms.'),
		);
		self::assertContains(
			"Control 'nope' does not exist on form 'form' (Corpus\\ProfileControl).",
			ConfigurationCorpus::messages($result['messages'], 'orisaiNette.latteForms.'),
		);
	}

	public function testLatteFormsWithoutForms(): void
	{
		$parameters = $this->quickStartParameters();
		$parameters['orisaiNette']['forms'] = ['enabled' => false];
		$result = $this->project->analyse($parameters, ConfigurationCorpus::PATHS);
		self::assertSame([], $result['errors'], $result['stderr']);

		self::assertSame(
			[],
			ConfigurationCorpus::findings($this->project, $result['messages'], 'orisaiNette.latteForms.'),
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
