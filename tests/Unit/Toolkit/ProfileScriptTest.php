<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Toolkit;

use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function dirname;
use const PHP_BINARY;

final class ProfileScriptTest extends BaseTestCase
{

	public function testProfileOverridesMergeOverComposerJson(): void
	{
		$root = dirname(__DIR__, 3);
		$composer = Json::decode(FileSystem::read($root . '/composer.json'), Json::FORCE_ARRAY);

		$merged = Json::decode($this->runProfileScript(['latte31', '--stdout']), Json::FORCE_ARRAY);

		self::assertSame('^3.1.4', $merged['require']['latte/latte']);
		self::assertSame('^3.3.0', $merged['require']['nette/application']);
		self::assertSame('^3.3.0', $merged['require']['nette/forms']);
		self::assertSame('^3.0.0', $merged['require-dev']['kdyby/forms-replicator']);
		self::assertSame('^3.4.0', $merged['require-dev']['nette/caching']);
		self::assertArrayNotHasKey('nette/application', $merged['require-dev']);
		self::assertSame('vendor-latte31', $merged['config']['vendor-dir']);
		self::assertSame($composer['config']['allow-plugins'], $merged['config']['allow-plugins']);
		self::assertSame(Json::encode($composer['autoload']), Json::encode($merged['autoload']));
		self::assertSame(Json::encode($composer['extra']), Json::encode($merged['extra']));
		self::assertArrayNotHasKey('php', $merged);
		self::assertArrayNotHasKey('ignore-platform-req', $merged);
	}

	public function testFlagsFollowThePlatformRequirementsIgnored(): void
	{
		self::assertSame("--ignore-platform-req=php\n", $this->runProfileScript(['latte2-nette32', '--flags']));
		self::assertSame("\n", $this->runProfileScript(['latte31', '--flags']));
	}

	public function testUnknownProfileFails(): void
	{
		$process = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/tools/profile.php', 'nonexistent', '--stdout']);
		$process->run();

		self::assertSame(1, $process->getExitCode());
		self::assertStringContainsString('Unknown profile "nonexistent"', $process->getErrorOutput());
	}

	public function testUnknownModeFails(): void
	{
		$process = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/tools/profile.php', 'latte31', '--print']);
		$process->run();

		self::assertSame(1, $process->getExitCode());
		self::assertStringContainsString('Unknown mode "--print"', $process->getErrorOutput());
		self::assertSame('', $process->getOutput());
	}

	/**
	 * @param list<string> $arguments
	 */
	private function runProfileScript(array $arguments): string
	{
		$process = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/tools/profile.php', ...$arguments]);
		$process->mustRun();

		return $process->getOutput();
	}

}
