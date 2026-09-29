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
use function in_array;
use function str_replace;
use function uniqid;
use function usort;
use const PHP_BINARY;

// Real subprocess spawn (LatteDiscoveryDiagnosticsIntegrationTest's pattern): proves
// TemplateTypeChecker is reachable through wiring.neon's real service graph - reached from the
// routing parser's own pass chain, with its record source, pairing judge and reflection provider
// resolved through the lazy container seam that exists precisely because eager injection would be a
// DI cycle. Two spawns per assertion: the writer links the templates on the first, the checker
// consumes those links on the second, exactly like the committed-store workflow in this repo.
/**
 * @group latte2
 */
final class LatteTemplateTypeIntegrationTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	private const IDENTIFIERS = [
		TemplateTypeChecker::MISMATCH_IDENTIFIER,
		TemplateTypeChecker::MISSING_IDENTIFIER,
		TemplateTypeChecker::REQUIRED_IDENTIFIER,
		TemplateTypeChecker::ORPHAN_IDENTIFIER,
	];

	public function testMismatchAndOrphanFireInARealAnalysisRunWhileTheStrictFlagStaysDormant(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $this->createScratchDir($projectRoot);
		$srcDir = $scratch . '/src';
		$relSrc = str_replace($projectRoot . '/', '', $srcDir);

		try {
			$this->writeFixture($srcDir);

			$messages = $this->messages($projectRoot, $srcDir, $scratch);

			// floor.latte is deliberately absent: its own renderer links it through the store, so the
			// fixpoint reaches it - only the file no renderer and no include names is orphaned. The
			// strict flag stays dormant here, so its untyped template contributes nothing either.
			self::assertCount(2, $messages, 'unexpected findings: ' . Json::encode($messages));

			self::assertSame(TemplateTypeChecker::ORPHAN_IDENTIFIER, $messages[0]['identifier']);
			self::assertSame("$relSrc/dead.latte", $messages[0]['file']);
			self::assertSame(
				'No analysable render, include or layout path reaches this template file.',
				$messages[0]['message'],
			);
			self::assertTrue($messages[0]['ignorable'], 'orphan findings must stay baselineable');

			self::assertSame(TemplateTypeChecker::MISMATCH_IDENTIFIER, $messages[1]['identifier']);
			self::assertSame("$relSrc/typed.latte", $messages[1]['file']);
			self::assertSame(1, $messages[1]['line']);
			self::assertTrue($messages[1]['ignorable'], 'templateType mismatches must stay baselineable');
			self::assertSame(
				'Template declares {templateType SpawnOtherTemplate} but renderer SpawnTypedControl '
				. 'pairs SpawnChildTemplate.',
				$messages[1]['message'],
			);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	// The flag-on half of the dormancy pin: the SAME corpus, with orisaiNette.latte.templateTypeRequired flipped,
	// reports the floor-verdict renderer's untyped template and nothing else new - a renderer whose
	// verdict names a real template class never qualifies however the flag is set.
	public function testStrictFlagOnReportsOnlyTheFloorVerdictRenderer(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $this->createScratchDir($projectRoot);
		$srcDir = $scratch . '/src';
		$relSrc = str_replace($projectRoot . '/', '', $srcDir);

		try {
			$this->writeFixture($srcDir);

			$messages = $this->messages(
				$projectRoot,
				$srcDir,
				$scratch,
				['orisaiNette.latte.templateTypeRequired' => true],
			);

			$required = [];
			foreach ($messages as $message) {
				if ($message['identifier'] === TemplateTypeChecker::REQUIRED_IDENTIFIER) {
					$required[] = $message;
				}
			}

			self::assertCount(1, $required, 'unexpected findings: ' . Json::encode($messages));
			self::assertSame("$relSrc/floor.latte", $required[0]['file']);
			self::assertSame(
				'Template has no {templateType} and renderer SpawnFloorControl pairs the default template class.',
				$required[0]['message'],
			);
			self::assertTrue($required[0]['ignorable'], 'strict findings must stay baselineable');
		} finally {
			FileSystem::delete($scratch);
		}
	}

	private function writeFixture(string $srcDir): void
	{
		FileSystem::write($srcDir . '/SpawnBaseTemplate.php', <<<'PHP'
<?php declare(strict_types = 1);

class SpawnBaseTemplate extends \Nette\Bridges\ApplicationLatte\Template
{

}

PHP);
		FileSystem::write($srcDir . '/SpawnChildTemplate.php', <<<'PHP'
<?php declare(strict_types = 1);

class SpawnChildTemplate extends SpawnBaseTemplate
{

}

PHP);
		FileSystem::write($srcDir . '/SpawnOtherTemplate.php', <<<'PHP'
<?php declare(strict_types = 1);

class SpawnOtherTemplate extends \Nette\Bridges\ApplicationLatte\Template
{

}

PHP);
		FileSystem::write($srcDir . '/SpawnTypedControl.php', <<<'PHP'
<?php declare(strict_types = 1);

final class SpawnTypedControl extends \Nette\Application\UI\Control
{

	protected function createTemplate(?string $class = null): SpawnChildTemplate
	{
		return new SpawnChildTemplate();
	}

	public function render(): void
	{
		$this->getTemplate()->setFile(__DIR__ . '/typed.latte');
	}

}

PHP);
		FileSystem::write($srcDir . '/SpawnFloorControl.php', <<<'PHP'
<?php declare(strict_types = 1);

final class SpawnFloorControl extends \Nette\Application\UI\Control
{

	public function render(): void
	{
		$this->getTemplate()->setFile(__DIR__ . '/floor.latte');
	}

}

PHP);
		// The declaration is deliberately narrower-than-unrelated: SpawnChildTemplate is no subtype
		// of SpawnOtherTemplate, so the widening rule cannot excuse it.
		FileSystem::write($srcDir . '/typed.latte', "{templateType SpawnOtherTemplate}\n<p>typed</p>\n");
		FileSystem::write($srcDir . '/floor.latte', "<p>floor</p>\n");
		// Linked by nobody and included by nobody: the reachability fixpoint's plain case.
		FileSystem::write($srcDir . '/dead.latte', "<p>dead</p>\n");
	}

	/**
	 * @param array<string, bool|int|string|list<string>> $extraParameters
	 * @return list<array{file: string, message: string, line: int, ignorable: bool, identifier: string}>
	 */
	private function messages(
		string $projectRoot,
		string $srcDir,
		string $scratch,
		array $extraParameters = []
	): array
	{
		$storeDir = $scratch . '/discovery';
		$relSrc = str_replace($projectRoot . '/', '', $srcDir);
		DiscoveryStore::bootstrap($storeDir, [
			"$relSrc/typed.latte",
			"$relSrc/floor.latte",
			"$relSrc/dead.latte",
		]);

		$parameters = $extraParameters + [
			'orisaiNette.latte.discovery.enabled' => true,
			'orisaiNette.latte.discovery.storePath' => $storeDir,
			'orisaiNette.latte.firstPartyPaths' => [$srcDir],
		];

		// Run 1 writes the store; run 2 is the one whose diagnostics the checker derives from it.
		$this->spawn($projectRoot, $srcDir, $scratch . '/pstmp', $parameters);

		return $this->spawn($projectRoot, $srcDir, $scratch . '/pstmp', $parameters);
	}

	/**
	 * @param array<string, bool|int|string|list<string>> $parameters
	 * @return list<array{file: string, message: string, line: int, ignorable: bool, identifier: string}>
	 */
	private function spawn(string $projectRoot, string $srcDir, string $tmpDir, array $parameters): array
	{
		$isolated = LattePhpstanConfig::create(self::REAL_CONFIG_PATH, [$srcDir], $tmpDir, $parameters);

		try {
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

			/** @var array{files: array<string, array{messages: list<array{message: string, line: int, ignorable: bool, identifier: string}>}>} $decoded */
			$decoded = Json::decode($process->getOutput(), Json::FORCE_ARRAY);

			$messages = [];
			foreach ($decoded['files'] as $file => $fileMessages) {
				foreach ($fileMessages['messages'] as $message) {
					if (!in_array($message['identifier'], self::IDENTIFIERS, true)) {
						continue;
					}

					$message['file'] = str_replace($projectRoot . '/', '', $file);
					$messages[] = $message;
				}
			}

			usort($messages, static fn (array $a, array $b): int => $a['file'] <=> $b['file']);

			return $messages;
		} finally {
			$isolated->cleanup();
		}
	}

	private function createScratchDir(string $projectRoot): string
	{
		// var/tmp/, never the system temp dir - see LatteDebugDumpIntegrationTest's own note on
		// ProjectRelativePath::relativize.
		$dir = $projectRoot . '/var/tmp/latte-templatetype-integration-test-' . uniqid('', true);
		FileSystem::createDir($dir);

		return $dir;
	}

}
