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
				'src/HomePresenter.php:18 orisai.nette.component.unattachedParentAccess',
				'src/ProfileControl.php:28 orisai.nette.forms.noSuchComponent',
			],
			[
				...ConfigurationCorpus::findings($this->project, $result['messages'], 'orisai.nette.component.'),
				...ConfigurationCorpus::findings($this->project, $result['messages'], 'orisai.nette.forms.'),
			],
		);
	}

	public function testLatte(): void
	{
		$result = $this->project->analyse($this->quickStartParameters(), ConfigurationCorpus::PATHS);
		self::assertSame([], $result['errors'], $result['stderr']);

		self::assertSame(
			['src/tpl.latte:2 orisai.nette.latte.unknownFilter'],
			ConfigurationCorpus::findings($this->project, $result['messages'], 'orisai.nette.latte.'),
		);
	}

	public function testLatteWithoutDiscovery(): void
	{
		$parameters = $this->quickStartParameters();
		$parameters['orisai']['nette']['latte']['discovery'] = ['enabled' => false];
		$result = $this->project->analyse($parameters, ConfigurationCorpus::PATHS);
		self::assertSame([], $result['errors'], $result['stderr']);

		self::assertSame(
			['src/tpl.latte:2 orisai.nette.latte.unknownFilter'],
			ConfigurationCorpus::findings($this->project, $result['messages'], 'orisai.nette.latte.'),
		);
	}

	// The corpus container has no presenter mapping, so with a mapping source configured the
	// presenter's template discovery is opaque - and switching discovery off silences exactly that.
	public function testDiscoveryOptOutSilencesOpaqueDiscovery(): void
	{
		$parameters = $this->quickStartParameters();
		$parameters['orisai']['nette']['latte']['templateFactoryContainerLoader'] = $this->project->path(
			'container-loader.php',
		);

		$enabled = $this->project->analyse($parameters, ConfigurationCorpus::PATHS);
		self::assertSame([], $enabled['errors'], $enabled['stderr']);
		self::assertSame(
			[
				'src/HomePresenter.php:7 orisai.nette.latte.fileDiscoveryOpaque',
				'src/tpl.latte:2 orisai.nette.latte.unknownFilter',
			],
			ConfigurationCorpus::findings($this->project, $enabled['messages'], 'orisai.nette.latte.'),
		);

		$parameters['orisai']['nette']['latte']['discovery'] = ['enabled' => false];
		$disabled = $this->project->analyse($parameters, ConfigurationCorpus::PATHS);
		self::assertSame([], $disabled['errors'], $disabled['stderr']);
		self::assertSame(
			['src/tpl.latte:2 orisai.nette.latte.unknownFilter'],
			ConfigurationCorpus::findings($this->project, $disabled['messages'], 'orisai.nette.latte.'),
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
			['src/ContainerConsumer.php:18 orisai.nette.dic.serviceNotFound'],
			ConfigurationCorpus::findings($this->project, $result['messages'], 'orisai.nette.dic.'),
		);
	}

	public function testLatteForms(): void
	{
		$result = $this->project->analyse($this->quickStartParameters(), ConfigurationCorpus::PATHS);
		self::assertSame([], $result['errors'], $result['stderr']);

		self::assertSame(
			['src/tpl.latte:1 orisai.nette.latteForms.unknownControl'],
			ConfigurationCorpus::findings($this->project, $result['messages'], 'orisai.nette.latteForms.'),
		);
		self::assertContains(
			"Control 'nope' does not exist on form 'form' (Corpus\\ProfileControl).",
			ConfigurationCorpus::messages($result['messages'], 'orisai.nette.latteForms.'),
		);
	}

	public function testLatteFormsWithoutForms(): void
	{
		$parameters = $this->quickStartParameters();
		$parameters['orisai']['nette']['forms'] = ['enabled' => false];
		$result = $this->project->analyse($parameters, ConfigurationCorpus::PATHS);
		self::assertSame([], $result['errors'], $result['stderr']);

		self::assertSame(
			[],
			ConfigurationCorpus::findings($this->project, $result['messages'], 'orisai.nette.latteForms.'),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function quickStartParameters(): array
	{
		return ConfigurationCorpus::PHPSTAN_NETTE_SWITCHES + [
			'fileExtensions' => ['php', 'latte'],
			'orisai' => ['nette' => [
				'latte' => ['enabled' => true],
				'dic' => ['containerLoader' => $this->project->path('container-loader.php')],
			]],
		];
	}

}
