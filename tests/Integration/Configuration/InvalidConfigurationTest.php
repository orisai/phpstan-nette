<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Configuration;

use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\ScratchProject;
use function implode;
use function sprintf;
use function strpos;

final class InvalidConfigurationTest extends BaseTestCase
{

	private ScratchProject $project;

	protected function setUp(): void
	{
		parent::setUp();
		$this->project = ScratchProject::create('invalid-configuration');
		$this->project->write('src/X.php', "<?php declare(strict_types = 1);\n\nfinal class X\n{\n\n}\n");
		$this->project->write('src/a.latte', "{\$x}\n");
		$this->project->write('loader.php', "<?php declare(strict_types = 1);\n\nreturn null;\n");
	}

	protected function tearDown(): void
	{
		$this->project->cleanup();
		parent::tearDown();
	}

	public function testValidConfigurationIsAccepted(): void
	{
		$result = $this->project->analyse([
			'fileExtensions' => ['php', 'latte'],
			'orisaiNette' => [
				'forms' => ['catalogs' => ['X']],
				'latte' => [
					'enabled' => true,
					'narrowing' => ['enabled' => true],
					'discovery' => [
						'enabled' => true,
						'formulas' => [
							'X' => 'samedir-single',
							'Y' => ['formula' => 'dirname-templates-lcfirst-fallback', 'sharedFallback' => 'form.latte'],
						],
					],
					'engineLoader' => $this->project->path('loader.php'),
					'templateFactoryContainerLoader' => $this->project->path('loader.php'),
				],
				'dic' => ['containerLoader' => $this->project->path('loader.php')],
			],
		], ['src']);

		self::assertSame([], $result['errors'], $result['stderr']);
		self::assertSame([], $result['messages']);
		self::assertSame(0, $result['exitCode'], $result['stderr']);
	}

	public function testLatteWithoutExtension(): void
	{
		$this->assertRejected(
			['orisaiNette' => ['latte' => ['enabled' => true]]],
			'orisaiNette.latte.enabled requires "latte" in fileExtensions.',
		);
	}

	public function testNarrowingWithoutLatte(): void
	{
		$this->assertRejected(
			['orisaiNette' => ['latte' => ['narrowing' => ['enabled' => true]]]],
			'orisaiNette.latte.narrowing.enabled requires orisaiNette.latte.enabled.',
		);
	}

	public function testDiscoveryWithoutLatte(): void
	{
		$this->assertRejected(
			['orisaiNette' => ['latte' => ['discovery' => ['enabled' => true]]]],
			'orisaiNette.latte.discovery.enabled requires orisaiNette.latte.enabled.',
		);
	}

	public function testMissingContainerLoader(): void
	{
		$path = $this->project->path('missing.php');
		$this->assertRejected(
			['orisaiNette' => ['dic' => ['containerLoader' => $path]]],
			sprintf('orisaiNette.dic.containerLoader "%s" is not a readable file.', $path),
		);
	}

	public function testMissingEngineLoader(): void
	{
		$path = $this->project->path('missing.php');
		$this->assertRejected(
			['orisaiNette' => ['latte' => ['engineLoader' => $path]]],
			sprintf('orisaiNette.latte.engineLoader "%s" is not a readable file.', $path),
		);
	}

	public function testMissingTemplateFactoryContainerLoader(): void
	{
		$path = $this->project->path('missing.php');
		$this->assertRejected(
			['orisaiNette' => ['latte' => ['templateFactoryContainerLoader' => $path]]],
			sprintf('orisaiNette.latte.templateFactoryContainerLoader "%s" is not a readable file.', $path),
		);
	}

	public function testMissingCatalogClass(): void
	{
		$this->assertRejected(
			['orisaiNette' => ['forms' => ['catalogs' => ['Nope\Missing']]]],
			'orisaiNette.forms.catalogs: class "Nope\Missing" does not exist.',
		);
	}

	public function testUnknownFormula(): void
	{
		$this->assertRejected(
			[
				'fileExtensions' => ['php', 'latte'],
				'orisaiNette' => [
					'latte' => [
						'enabled' => true,
						'discovery' => ['enabled' => true, 'formulas' => ['X' => 'unknown-formula']],
					],
				],
			],
			'orisaiNette.latte.discovery.formulas: unknown formula "unknown-formula" for X.',
		);
	}

	/**
	 * @param array<string, mixed> $parameters
	 */
	private function assertRejected(array $parameters, string $message): void
	{
		$result = $this->project->analyse($parameters, ['src']);

		self::assertNotSame(0, $result['exitCode']);

		$output = implode("\n", $result['errors']) . "\n" . $result['stderr'];
		self::assertNotFalse(strpos($output, $message), $output);
	}

}
