<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration;

use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use OriPhpstan\Nette\Latte\Includes\TemplateTypeChecker;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function dirname;
use function implode;
use function sort;
use function str_replace;
use function strpos;
use function uniqid;
use const PHP_BINARY;

// The PER-FILE half of the discovery consumers - orisaiNette.latte.templateTypeMismatch is evaluated against
// EVERY renderer the store links to a template, so a SECOND renderer added later changes a verdict
// the template's own bytes never reflect. Nothing PHPStan sees about the template changed: its
// content hash is the same, its {templateType} is the same, the store's template-file SET is the
// same (every analysed template already owns a store file), and the new renderer was never a
// dependency of it. The only thing that moved is the per-template store file's own RECORDS_HASH.
//
// Two regimes, both pinned here because they invalidate through completely different channels:
// with the store directory INSIDE the analysed paths that byte change is an ordinary exported-node
// change PHPStan propagates to the self-referencing template; OUTSIDE it, PHPStan tracks nothing at
// all and only LatteResultCacheMeta's whole-store content salt closes the window. Before that salt
// existed the outside layout settled at "0 files will be reanalysed" with the mismatch never
// reported, while a cold run at the same state reported it - the cold != warm this test exists to
// keep closed.
final class DiscoveryPerFileConsumerInvalidationTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	private const SETTLED = 'Result cache restored. 0 files will be reanalysed.';

	public function testSecondRendererReachesTheMismatchConsumerWithTheStoreInsideTheAnalysedPaths(): void
	{
		$this->assertSecondRendererConverges('/src/discovery');
	}

	public function testSecondRendererReachesTheMismatchConsumerWithTheStoreOutsideTheAnalysedPaths(): void
	{
		$this->assertSecondRendererConverges('/discovery');
	}

	private function assertSecondRendererConverges(string $storeSubPath): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $projectRoot . '/var/tmp/latte-per-file-consumer-test-' . uniqid('', true);
		$srcDir = $scratch . '/src';
		$storeDir = $scratch . $storeSubPath;
		$tmpDir = $scratch . '/pstmp';
		$relSrc = str_replace($projectRoot . '/', '', $srcDir);

		try {
			$this->writeFixture($srcDir);
			DiscoveryStore::bootstrap($storeDir, ["$relSrc/shared.latte"]);

			$base = $this->settle($projectRoot, $srcDir, $tmpDir, $storeDir);
			self::assertStringNotContainsString(
				TemplateTypeChecker::MISMATCH_IDENTIFIER,
				$base,
				'the only renderer pairs the declared class, so nothing may be reported yet',
			);

			// The whole subject: a second renderer, pairing a class outside the declaration.
			FileSystem::write($srcDir . '/ScratchSecondControl.php', <<<'PHP'
<?php declare(strict_types = 1);

final class ScratchSecondControl extends \Nette\Application\UI\Control
{

	protected function createTemplate(?string $class = null): ScratchOtherTemplate
	{
		return new ScratchOtherTemplate(new \Latte\Engine());
	}

	public function render(): void
	{
		$this->getTemplate()->setFile(__DIR__ . '/shared.latte');
	}

}

PHP);

			$warm = $this->settle($projectRoot, $srcDir, $tmpDir, $storeDir);
			self::assertStringContainsString(
				"$relSrc/shared.latte:1 :: " . TemplateTypeChecker::MISMATCH_IDENTIFIER
				. ' :: Template declares {templateType ScratchBaseTemplate} but renderer '
				. 'ScratchSecondControl pairs ScratchOtherTemplate.',
				$warm,
				'the warm fixpoint must report the second renderer: ' . $warm,
			);

			// cold == warm at the settled state: everything on disk is identical, only the result
			// cache is gone, so any difference is a cached verdict served past its inputs.
			FileSystem::delete($tmpDir . '/resultCache.php');

			$cold = $this->spawn($projectRoot, $srcDir, $tmpDir, $storeDir)['errors'];
			self::assertSame($warm, $cold, 'cold == warm');
		} finally {
			FileSystem::delete($scratch);
		}
	}

	private function writeFixture(string $srcDir): void
	{
		FileSystem::write($srcDir . '/ScratchBaseTemplate.php', <<<'PHP'
<?php declare(strict_types = 1);

class ScratchBaseTemplate extends \Nette\Bridges\ApplicationLatte\Template
{

}

PHP);
		FileSystem::write($srcDir . '/ScratchOtherTemplate.php', <<<'PHP'
<?php declare(strict_types = 1);

class ScratchOtherTemplate extends \Nette\Bridges\ApplicationLatte\Template
{

}

PHP);
		FileSystem::write($srcDir . '/ScratchFirstControl.php', <<<'PHP'
<?php declare(strict_types = 1);

final class ScratchFirstControl extends \Nette\Application\UI\Control
{

	protected function createTemplate(?string $class = null): ScratchBaseTemplate
	{
		return new ScratchBaseTemplate(new \Latte\Engine());
	}

	public function render(): void
	{
		$this->getTemplate()->setFile(__DIR__ . '/shared.latte');
	}

}

PHP);
		FileSystem::write($srcDir . '/shared.latte', "{templateType ScratchBaseTemplate}\n<p>shared</p>\n");
	}

	// The writer links on one run and the linked template consumes those links on the next, so the
	// subject is always the FIXPOINT, never any single run's output.
	private function settle(string $projectRoot, string $srcDir, string $tmpDir, string $storeDir): string
	{
		$errors = '';
		for ($i = 0; $i < 6; $i++) {
			$run = $this->spawn($projectRoot, $srcDir, $tmpDir, $storeDir);
			$errors = $run['errors'];
			if (strpos($run['diagnostics'], self::SETTLED) !== false) {
				return $errors;
			}
		}

		self::fail('the analysis never reached a fixpoint: ' . $errors);
	}

	/**
	 * @return array{errors: string, diagnostics: string}
	 */
	private function spawn(string $projectRoot, string $srcDir, string $tmpDir, string $storeDir): array
	{
		$isolated = LattePhpstanConfig::create(
			self::REAL_CONFIG_PATH,
			[$srcDir],
			$tmpDir,
			[
				'orisaiNette.latte.discovery.enabled' => true,
				'orisaiNette.latte.discovery.storePath' => $storeDir,
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
				'-vv',
				'-c',
				$isolated->getConfigPath(),
			],
			$projectRoot,
		);
		$process->run();

		/** @var array{files: array<string, array{messages: list<array{message: string, line: int, identifier: string}>}>} $decoded */
		$decoded = Json::decode($process->getOutput(), Json::FORCE_ARRAY);

		$lines = [];
		foreach ($decoded['files'] as $file => $fileMessages) {
			foreach ($fileMessages['messages'] as $message) {
				$lines[] = str_replace($projectRoot . '/', '', $file) . ':' . $message['line']
					. ' :: ' . $message['identifier'] . ' :: ' . $message['message'];
			}
		}

		sort($lines);

		return ['errors' => implode("\n", $lines), 'diagnostics' => $process->getErrorOutput()];
	}

}
