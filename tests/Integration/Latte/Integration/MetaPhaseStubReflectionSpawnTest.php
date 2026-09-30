<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration;

use Nette\Utils\FileSystem;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InstalledVersionsGuard;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function dirname;
use function sprintf;
use function uniqid;
use const PHP_BINARY;
use const PHP_VERSION;
use const PHP_VERSION_ID;

// The pre-analysis index build runs inside PHPStan's result-cache meta phase, before PHPStan 2.2.10+
// loads its PHP 7 runtime stubs (ReflectionAttribute among them); resolving ScratchItems<int, string>
// there reflects the attributed getIterator().
final class MetaPhaseStubReflectionSpawnTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	public function testTheMetaPhaseResolvesAnAttributedStubMethod(): void
	{
		if (PHP_VERSION_ID >= 80000 || !InstalledVersionsGuard::satisfies('phpstan/phpstan', '>=2.2.10')) {
			self::markTestSkipped(sprintf(
				'The meta-phase stub crash needs PHP 7 with PHPStan 2.2.10+ (spawned: PHP %s, PHPStan %s)',
				PHP_VERSION,
				InstalledVersionsGuard::version('phpstan/phpstan') ?? 'none',
			));
		}

		$projectRoot = dirname(__DIR__, 4);
		$scratch = $projectRoot . '/var/tmp/latte-meta-phase-stubs-' . uniqid('', true);
		$srcDir = $scratch . '/src';

		try {
			FileSystem::write(
				$srcDir . '/ScratchItemsRenderer.php',
				"<?php declare(strict_types = 1);\n\n"
				. "/**\n"
				. " * @property-read Nette\\Bridges\\ApplicationLatte\\DefaultTemplate \$template\n"
				. " */\n"
				. "final class ScratchItemsRenderer extends Nette\\Application\\UI\\Control\n"
				. "{\n\n"
				. "\tpublic function render(): void\n"
				. "\t{\n"
				. "\t\t\$this->template->items = \$this->items();\n"
				. "\t\t\$this->template->setFile(__DIR__ . '/items.latte');\n"
				. "\t\t\$this->template->render();\n"
				. "\t}\n\n"
				. "\t/**\n"
				. "\t * @return ScratchItems<int, string>\n"
				. "\t */\n"
				. "\tprivate function items(): ScratchItems\n"
				. "\t{\n"
				. "\t\treturn new ScratchItems();\n"
				. "\t}\n\n"
				. "}\n",
			);
			FileSystem::write(
				$srcDir . '/ScratchItems.php',
				"<?php declare(strict_types = 1);\n\n"
				. "final class ScratchItems implements IteratorAggregate\n"
				. "{\n\n"
				. "\t/**\n"
				. "\t * @return Iterator<int, string>\n"
				. "\t */\n"
				. "\t#[\\ReturnTypeWillChange]\n"
				. "\tpublic function getIterator(): Iterator\n"
				. "\t{\n"
				. "\t\treturn new ArrayIterator(['a']);\n"
				. "\t}\n\n"
				. "}\n",
			);
			FileSystem::write($srcDir . '/items.latte', "{foreach \$items as \$item}{\$item}{/foreach}\n");

			$isolated = LattePhpstanConfig::create(
				self::REAL_CONFIG_PATH,
				[$srcDir],
				$scratch . '/tmp',
				[
					'orisaiNette.latte.discovery.enabled' => true,
					'orisaiNette.latte.discovery.storePath' => $srcDir . '/discovery',
					'orisaiNette.latte.firstPartyPaths' => [$srcDir],
				],
			);

			$process = new Process(
				[
					PHP_BINARY,
					$projectRoot . '/' . VendorDirectory::name() . '/bin/phpstan',
					'analyse',
					'--no-progress',
					'--level=8',
					'--error-format=json',
					'-c',
					$isolated->getConfigPath(),
				],
				$projectRoot,
			);
			$process->run();
			$output = $process->getOutput() . $process->getErrorOutput();

			self::assertStringNotContainsString('ReflectionAttribute', $output, $output);
			self::assertStringContainsString('"errors":[]', $process->getOutput(), $output);
		} finally {
			FileSystem::delete($scratch);
		}
	}

}
