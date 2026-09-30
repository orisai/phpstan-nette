<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Configuration;

use Nette\Neon\Entity;
use Nette\Neon\Neon;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\ScratchProject;
use function implode;

final class SiblingExtensionTest extends BaseTestCase
{

	private ScratchProject $project;

	protected function setUp(): void
	{
		parent::setUp();
		$this->project = ScratchProject::create('sibling-extension');
		$this->project->write('src/X.php', "<?php declare(strict_types = 1);\n\nfinal class X\n{\n\n}\n");
		$this->project->write('sibling/extension.neon', Neon::encode([
			'parametersSchema' => [
				'orisai' => new Entity('arrayOf', [new Entity('array', []), new Entity('string', [])]),
			],
			'parameters' => [
				'orisai' => ['sibling' => ['flag' => false, 'name' => 'default']],
			],
			'services' => [
				'siblingProbe' => [
					'class' => ParameterProbeRule::class,
					'arguments' => ['label' => 'sibling', 'value' => '%orisai.sibling%'],
					'tags' => ['phpstan.rules.rule'],
				],
			],
		], true));
	}

	protected function tearDown(): void
	{
		$this->project->cleanup();
		parent::tearDown();
	}

	/**
	 * @dataProvider provideOrder
	 */
	public function testBothSubtreesKeepTheirDefaultsAndUserValues(bool $siblingFirst): void
	{
		$result = $this->analyse($siblingFirst, [
			'nette' => ['latte' => ['firstPartyPaths' => [$this->project->path('src/App')]]],
			'sibling' => ['flag' => true],
		]);

		self::assertSame([], $result['errors'], $result['stderr']);
		self::assertSame(
			[
				'firstPartyPaths=["' . $this->project->path('src/App') . '"]',
				'sibling={"flag":true,"name":"default"}',
			],
			ConfigurationCorpus::messages($result['messages'], ParameterProbeRule::IDENTIFIER),
		);
	}

	/**
	 * @dataProvider provideOrder
	 */
	public function testFirstPartyPathsDefaultToThePaths(bool $siblingFirst): void
	{
		$result = $this->analyse($siblingFirst, ['sibling' => ['flag' => true]]);

		self::assertSame([], $result['errors'], $result['stderr']);
		self::assertSame(
			[
				'firstPartyPaths=["' . $this->project->path('src') . '"]',
				'sibling={"flag":true,"name":"default"}',
			],
			ConfigurationCorpus::messages($result['messages'], ParameterProbeRule::IDENTIFIER),
		);
	}

	/**
	 * @dataProvider provideOrder
	 */
	public function testTypoUnderNetteIsRejected(bool $siblingFirst): void
	{
		$result = $this->analyse($siblingFirst, [
			'nette' => ['latte' => ['enabledd' => true]],
			'sibling' => ['flag' => true],
		]);

		self::assertNotSame(0, $result['exitCode']);
		$output = implode("\n", $result['errors']) . "\n" . $result['stderr'];
		self::assertStringContainsString(
			"Unexpected item 'parameters\u{a0}›\u{a0}orisai\u{a0}›\u{a0}nette\u{a0}›\u{a0}latte\u{a0}›\u{a0}enabledd'",
			$output,
		);
	}

	/**
	 * @return iterable<string, array{bool}>
	 */
	public function provideOrder(): iterable
	{
		yield 'sibling included first' => [true];

		yield 'sibling included last' => [false];
	}

	/**
	 * @param array<string, mixed> $orisai
	 * @return array{exitCode: int, messages: list<array{file: string, line: int, message: string, identifier: string|null}>, errors: list<string>, stderr: string}
	 */
	private function analyse(bool $siblingFirst, array $orisai): array
	{
		$sibling = $this->project->path('sibling/extension.neon');
		$extension = $this->project->extensionConfig();

		return $this->project->analyse(
			['orisai' => $orisai],
			['src'],
			[
				'firstPartyPathsProbe' => [
					'class' => ParameterProbeRule::class,
					'arguments' => [
						'label' => 'firstPartyPaths',
						'value' => new Entity('@orisai.nette.configuration::get', ['latte.firstPartyPaths']),
					],
					'tags' => ['phpstan.rules.rule'],
				],
			],
			[],
			$siblingFirst ? [$sibling, $extension] : [$extension, $sibling],
		);
	}

}
