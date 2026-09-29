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
use function array_column;
use function dirname;
use function sort;
use function str_replace;
use function uniqid;
use function usort;
use const PHP_BINARY;
use const SORT_STRING;

// The weakest point of the "a {templateType} declaration is a contract" reading, measured rather
// than argued: ONE template, TWO linked renderer classes, and only one of them ever writes the
// declared property. The dumps in the same spawn prove the walk HAS that write information per
// renderer (one carries the assignment, the other reports none) - and the template's own findings
// prove the declared-parameter channel never consults it. Both declared properties stay defined for
// every renderer, including the one that writes neither. See docs/phpstan-latte.md's
// "A declared property is a parameter even when nothing ever writes it" limitation.
/**
 * @group latte2
 */
final class DeclaredParametersMultiRendererTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	public function testDeclaredPropertiesStayDefinedForEveryLinkedRendererIncludingTheOneWritingNothing(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		// var/tmp/, never the system temp dir - see LatteDebugDumpIntegrationTest's own note on
		// ProjectRelativePath::relativize.
		$scratch = $projectRoot . '/var/tmp/latte-declared-multi-renderer-' . uniqid('', true);
		$srcDir = $scratch . '/src';
		$relSrc = str_replace($projectRoot . '/', '', $srcDir);
		$storeDir = $scratch . '/discovery';

		try {
			$this->writeFixture($srcDir);

			$sharedRel = "$relSrc/shared.latte";
			// Every template's store file exists BEFORE run 1 (mirrors the pre-analysis index
			// build), as FactoryVarsRendererHierarchyInvalidationTest's own precondition does.
			DiscoveryStore::bootstrap($storeDir, [$sharedRel, "$relSrc/facts.latte"]);

			$tmpDir = $scratch . '/pstmp';
			$bootstrapFile = $this->writeBootstrap($scratch, $srcDir);

			// Run 1 writes the store; run 2 is the one whose findings the assertions read.
			$this->spawn($projectRoot, $srcDir, $tmpDir, $storeDir, $bootstrapFile);

			// The probe's own precondition: without BOTH renderers linked to the one template there
			// is no multi-renderer case to measure at all.
			$linked = array_column((new DiscoveryStore($storeDir))->recordsForTemplate($sharedRel), 'class');
			sort($linked, SORT_STRING);
			self::assertSame(
				['SpawnSilentControl', 'SpawnWritingControl'],
				$linked,
				'run 1 must link shared.latte to BOTH renderers - correct the fixture before reading '
				. 'anything below',
			);

			$messages = $this->spawn($projectRoot, $srcDir, $tmpDir, $storeDir, $bootstrapFile);

			$dumps = $this->filter($messages, 'orisaiNette.latte.debugDump');
			self::assertCount(2, $dumps, 'both renderers must dump their facts: ' . Json::encode($messages));
			self::assertSame(
				'class: SpawnSilentControl'
				. "\ntemplate class: SpawnDeclaredTemplate (phpdoc, happens)"
				. "\ntemplate class candidates:"
				. "\nSpawnDeclaredTemplate (phpdoc, happens)"
				. "\nSpawnDeclaredTemplate (genericBinding, happens)"
				. "\nassignments: (none)"
				. "\nsetFile targets:"
				. "\nliteral '$relSrc/shared.latte' (happens) @ $relSrc/SpawnSilentControl.php:11"
				. "\nrender sites:"
				. "\n$relSrc/SpawnSilentControl.php:12 (no file arg)",
				$dumps[0]['message'],
				'the silent renderer writes nothing, and the walk sees exactly that',
			);
			self::assertSame(
				'class: SpawnWritingControl'
				. "\ntemplate class: SpawnDeclaredTemplate (phpdoc, happens)"
				. "\ntemplate class candidates:"
				. "\nSpawnDeclaredTemplate (phpdoc, happens)"
				. "\nSpawnDeclaredTemplate (genericBinding, happens)"
				. "\nassignments:"
				. "\n\$writtenByOneRenderer: int (happens) @ $relSrc/SpawnWritingControl.php:11"
				. "\nsetFile targets:"
				. "\nliteral '$relSrc/shared.latte' (happens) @ $relSrc/SpawnWritingControl.php:12"
				. "\nrender sites:"
				. "\n$relSrc/SpawnWritingControl.php:13 (no file arg)",
				$dumps[1]['message'],
				'the writing renderer writes exactly one of the two declared properties',
			);

			// The measurement itself: neither declared property is reported, for either renderer -
			// including $writtenByNoRenderer, which no analysable code writes anywhere. Only the
			// variable no channel declares at all is reported, which is what proves this run still
			// reports undefined variables.
			$undefined = $this->filter($messages, 'variable.undefined');
			self::assertCount(1, $undefined, 'unexpected undefined variables: ' . Json::encode($messages));
			self::assertSame("$relSrc/shared.latte", $undefined[0]['file']);
			self::assertSame(4, $undefined[0]['line']);
			self::assertSame('Undefined variable: $neverDeclared', $undefined[0]['message']);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	private function writeFixture(string $srcDir): void
	{
		FileSystem::write($srcDir . '/SpawnDeclaredTemplate.php', <<<'PHP'
<?php declare(strict_types = 1);

class SpawnDeclaredTemplate extends Nette\Bridges\ApplicationLatte\Template
{

	public int $writtenByOneRenderer;

	public int $writtenByNoRenderer;

}

PHP);
		FileSystem::write($srcDir . '/SpawnWritingControl.php', <<<'PHP'
<?php declare(strict_types = 1);

/**
 * @property-read SpawnDeclaredTemplate $template
 */
final class SpawnWritingControl extends Nette\Application\UI\Control
{

	public function render(): void
	{
		$this->template->writtenByOneRenderer = 1;
		$this->template->setFile(__DIR__ . '/shared.latte');
		$this->template->render();
	}

}

PHP);
		FileSystem::write($srcDir . '/SpawnSilentControl.php', <<<'PHP'
<?php declare(strict_types = 1);

/**
 * @property-read SpawnDeclaredTemplate $template
 */
final class SpawnSilentControl extends Nette\Application\UI\Control
{

	public function render(): void
	{
		$this->template->setFile(__DIR__ . '/shared.latte');
		$this->template->render();
	}

}

PHP);
		FileSystem::write(
			$srcDir . '/shared.latte',
			"{templateType SpawnDeclaredTemplate}\n"
			. "{\$writtenByOneRenderer}\n"
			. "{\$writtenByNoRenderer}\n"
			. "{\$neverDeclared}\n",
		);
		// The dumps live in their own template so shared.latte stays a plain consumer of the
		// declared parameter set - a {do} call in it would compile into the very main() whose
		// variable definedness is the measurement.
		FileSystem::write(
			$srcDir . '/facts.latte',
			"{do \\OriPhpstan\\Nette\\Latte\\Testing\\dumpLatteRenderFacts(\\SpawnWritingControl::class)}\n"
			. "{do \\OriPhpstan\\Nette\\Latte\\Testing\\dumpLatteRenderFacts(\\SpawnSilentControl::class)}\n",
		);
	}

	// {templateType} resolution is a runtime class_exists() autoload, and a scratch corpus has no
	// PSR-4 mapping - see LattePhpstanConfig::create()'s own $extraBootstrapFiles doc.
	private function writeBootstrap(string $scratch, string $srcDir): string
	{
		$path = $scratch . '/bootstrap-classes.php';
		FileSystem::write(
			$path,
			"<?php declare(strict_types = 1);\n\n"
			. "require '" . dirname($srcDir, 4) . "/tests/autoload.php';\n"
			. "require '$srcDir/SpawnDeclaredTemplate.php';\n"
			. "require '$srcDir/SpawnWritingControl.php';\n"
			. "require '$srcDir/SpawnSilentControl.php';\n",
		);

		return $path;
	}

	/**
	 * @param list<array{file: string, message: string, line: int, identifier: string}> $messages
	 * @return list<array{file: string, message: string, line: int, identifier: string}>
	 */
	private function filter(array $messages, string $identifier): array
	{
		$matched = [];
		foreach ($messages as $message) {
			if ($message['identifier'] === $identifier) {
				$matched[] = $message;
			}
		}

		return $matched;
	}

	/**
	 * @return list<array{file: string, message: string, line: int, identifier: string}>
	 */
	private function spawn(
		string $projectRoot,
		string $srcDir,
		string $tmpDir,
		string $storeDir,
		string $bootstrapFile
	): array
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
			[$bootstrapFile],
		);

		try {
			// --error-format=json, not raw: a dump message is multi-line, and raw's per-physical-line
			// rendering would fight the assertions - LatteDebugDumpIntegrationTest's own rationale.
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

			/** @var array{files: array<string, array{messages: list<array{message: string, line: int, identifier: string}>}>} $decoded */
			$decoded = Json::decode($process->getOutput(), Json::FORCE_ARRAY);

			$messages = [];
			foreach ($decoded['files'] as $file => $fileMessages) {
				foreach ($fileMessages['messages'] as $message) {
					// facts.latte is linked by nobody by design (it exists to host the dumps), so its
					// orphan verdict carries no information here.
					if ($message['identifier'] === TemplateTypeChecker::ORPHAN_IDENTIFIER) {
						continue;
					}

					$message['file'] = str_replace($projectRoot . '/', '', $file);
					$messages[] = $message;
				}
			}

			usort(
				$messages,
				static fn (array $a, array $b): int => [$a['file'], $a['message']] <=> [$b['file'], $b['message']],
			);

			return $messages;
		} finally {
			$isolated->cleanup();
		}
	}

}
