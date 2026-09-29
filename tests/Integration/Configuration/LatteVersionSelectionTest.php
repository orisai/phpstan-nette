<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Configuration;

use Nette\Neon\Entity;
use OriPhpstan\Nette\Support\ProjectInstalledVersions;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\ScratchProject;
use function implode;
use function strpos;

// Spawns PHPStan with the installed-version service overridden: the version switch must never run
// while the container is built, so an unsupported install is inert with Latte off and the guard's
// own message with Latte on.
final class LatteVersionSelectionTest extends BaseTestCase
{

	private ScratchProject $project;

	protected function setUp(): void
	{
		parent::setUp();
		$this->project = ScratchProject::create('latte-version-selection');
		$this->project->write('src/X.php', "<?php declare(strict_types = 1);\n\nfinal class X\n{\n\n}\n");
	}

	protected function tearDown(): void
	{
		$this->project->cleanup();
		parent::tearDown();
	}

	public function testLatte3InstallWithLatteOffRunsClean(): void
	{
		$result = $this->analyse(false, '3.1.6.0', 'v3.1.6');

		self::assertSame([], $result['errors'], $result['stderr']);
		self::assertSame([], $result['messages']);
		self::assertSame(0, $result['exitCode'], $result['stderr']);
	}

	public function testUnsupportedInstallWithLatteOnIsTheGuardMessage(): void
	{
		$result = $this->analyse(true, 'dev-master', 'dev-master');

		self::assertNotSame(0, $result['exitCode']);

		$output = implode("\n", $result['errors']) . "\n" . $result['stderr'];
		self::assertNotFalse(strpos(
			$output,
			'orisaiNette.latte.enabled requires a supported latte/latte version (2.11, 3.0 or 3.1); installed dev-master.',
		), $output);
		self::assertFalse(strpos($output, 'Unsupported latte/latte version'), $output);
	}

	public function testLatte2InstallWithLatteOnRunsClean(): void
	{
		$result = $this->analyse(true, '2.11.7.0', 'v2.11.7');

		self::assertSame([], $result['errors'], $result['stderr']);
		self::assertSame([], $result['messages']);
		self::assertSame(0, $result['exitCode'], $result['stderr']);
	}

	/**
	 * @return array{exitCode: int, messages: list<array{file: string, line: int, message: string, identifier: string|null}>, errors: list<string>, stderr: string}
	 */
	private function analyse(bool $latteEnabled, string $latteVersion, string $lattePrettyVersion): array
	{
		$installed = [
			ProjectInstalledVersions::PACKAGE => ['version' => '1.0.0.0', 'pretty_version' => '1.0.0'],
			'latte/latte' => ['version' => $latteVersion, 'pretty_version' => $lattePrettyVersion],
			'nette/forms' => ['version' => '3.3.0.0', 'pretty_version' => 'v3.3.0'],
			'nette/application' => ['version' => '3.3.0.0', 'pretty_version' => 'v3.3.0'],
		];

		return $this->project->analyse(
			[
				'fileExtensions' => ['php', 'latte'],
				'orisaiNette' => ['latte' => ['enabled' => $latteEnabled]],
			],
			['src'],
			[
				'orisaiNette.installedVersions' => [
					'factory' => new Entity(
						ProjectInstalledVersions::class . '::fromRawData',
						[[['root' => [], 'versions' => $installed]]],
					),
					'autowired' => false,
				],
			],
		);
	}

}
