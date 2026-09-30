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
		$this->project->write('src/a.latte', "{var \$x = 1}{\$x}\n");
		$this->project->write('loader.php', "<?php declare(strict_types = 1);\n\nreturn null;\n");
		$this->project->write(
			'container-loader.php',
			"<?php declare(strict_types = 1);\n\nreturn ['default' => new Nette\\DI\\Container()];\n",
		);
	}

	protected function tearDown(): void
	{
		$this->project->cleanup();
		parent::tearDown();
	}

	/**
	 * @group latte2
	 */
	public function testValidConfigurationIsAccepted(): void
	{
		$result = $this->project->analyse([
			'fileExtensions' => ['php', 'latte'],
			'orisai' => ['nette' => [
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
				'dic' => ['containerLoader' => $this->project->path('container-loader.php')],
			]],
		], ['src']);

		self::assertSame([], $result['errors'], $result['stderr']);
		self::assertSame([], $result['messages']);
		self::assertSame(0, $result['exitCode'], $result['stderr']);
	}

	public function testLatteWithoutExtension(): void
	{
		$this->assertRejected(
			['orisai' => ['nette' => ['latte' => ['enabled' => true]]]],
			'orisai.nette.latte.enabled requires "latte" in fileExtensions.',
		);
	}

	public function testNarrowingWithoutLatte(): void
	{
		$this->assertRejected(
			['orisai' => ['nette' => ['latte' => ['narrowing' => ['enabled' => true]]]]],
			'orisai.nette.latte.narrowing.enabled requires orisai.nette.latte.enabled.',
		);
	}

	public function testDiscoveryWithoutLatteIsInert(): void
	{
		$result = $this->project->analyse(
			['orisai' => ['nette' => ['latte' => ['discovery' => ['enabled' => true]]]]],
			['src'],
		);

		self::assertSame([], $result['errors'], $result['stderr']);
		self::assertSame(0, $result['exitCode'], $result['stderr']);
	}

	public function testMissingContainerLoader(): void
	{
		$path = $this->project->path('missing.php');
		$this->assertRejected(
			['orisai' => ['nette' => ['dic' => ['containerLoader' => $path]]]],
			sprintf('orisai.nette.dic.containerLoader "%s" is not a readable file.', $path),
		);
	}

	public function testMissingEngineLoader(): void
	{
		$path = $this->project->path('missing.php');
		$this->assertRejected(
			['orisai' => ['nette' => ['latte' => ['engineLoader' => $path]]]],
			sprintf('orisai.nette.latte.engineLoader "%s" is not a readable file.', $path),
		);
	}

	public function testMissingTemplateFactoryContainerLoader(): void
	{
		$path = $this->project->path('missing.php');
		$this->assertRejected(
			['orisai' => ['nette' => ['latte' => ['templateFactoryContainerLoader' => $path]]]],
			sprintf('orisai.nette.latte.templateFactoryContainerLoader "%s" is not a readable file.', $path),
		);
	}

	public function testMissingCatalogClass(): void
	{
		$this->assertRejected(
			['orisai' => ['nette' => ['forms' => ['catalogs' => ['Nope\Missing']]]]],
			'orisai.nette.forms.catalogs: class "Nope\Missing" does not exist.',
		);
	}

	public function testUnknownFormula(): void
	{
		$this->assertRejected(
			[
				'fileExtensions' => ['php', 'latte'],
				'orisai' => ['nette' => [
					'latte' => [
						'enabled' => true,
						'discovery' => ['enabled' => true, 'formulas' => ['X' => 'unknown-formula']],
					],
				]],
			],
			'orisai.nette.latte.discovery.formulas: unknown formula "unknown-formula" for X.',
		);
	}

	public function testUnknownFormulaOption(): void
	{
		$this->assertRejected(
			[
				'fileExtensions' => ['php', 'latte'],
				'orisai' => ['nette' => [
					'latte' => [
						'enabled' => true,
						'discovery' => [
							'enabled' => true,
							'formulas' => ['X' => ['formula' => 'samedir-single', 'sharedFallbak' => 'form.latte']],
						],
					],
				]],
			],
			'orisai.nette.latte.discovery.formulas: unknown option "sharedFallbak" for X.',
		);
	}

	public function testFormulaMissingItsRequiredOption(): void
	{
		$this->assertRejected(
			[
				'fileExtensions' => ['php', 'latte'],
				'orisai' => ['nette' => [
					'latte' => [
						'enabled' => true,
						'discovery' => ['enabled' => true, 'formulas' => ['X' => 'dirname-property-lcfirst']],
					],
				]],
			],
			'orisai.nette.latte.discovery.formulas: formula "dirname-property-lcfirst" for X requires option "nameProperty".',
		);
	}

	public function testDuplicateCatalogMethod(): void
	{
		$this->project->write(
			'src/DuplicateCatalog.php',
			"<?php declare(strict_types = 1);\n\ninterface DuplicateCatalog\n{\n\n"
				. "\t/** @form-read-type int */\n\tpublic function ADDTEXT(): void;\n\n}\n",
		);

		$this->assertRejected(
			['orisai' => ['nette' => ['forms' => ['catalogs' => ['DuplicateCatalog']]]]],
			'orisai.nette.forms.catalogs: method "ADDTEXT" is declared by both '
				. 'OriPhpstan\\Nette\\Forms\\Catalog\\Stub\\FormValueTypeCatalog and DuplicateCatalog.',
		);
	}

	public function testCatalogExtendingABuiltInIsADuplicate(): void
	{
		$this->project->write(
			'src/ExtendingCatalog.php',
			"<?php declare(strict_types = 1);\n\n"
				. "interface ExtendingCatalog extends OriPhpstan\\Nette\\Forms\\Catalog\\Stub\\FormValueTypeCatalog\n{\n\n}\n",
		);

		$this->assertRejected(
			['orisai' => ['nette' => ['forms' => ['catalogs' => ['ExtendingCatalog']]]]],
			'orisai.nette.forms.catalogs: method "addText" is declared by both '
				. 'OriPhpstan\\Nette\\Forms\\Catalog\\Stub\\FormValueTypeCatalog and ExtendingCatalog.',
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
