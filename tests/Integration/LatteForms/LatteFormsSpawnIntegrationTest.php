<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\LatteForms;

use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use OriPhpstan\Nette\LatteForms\LatteFormsRule;
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

// Real subprocess spawn: the unit test proves the rule DECIDES correctly, this proves it is
// REGISTERED - that config/latteForms.neon's service graph builds, that the switches reach it, and that a finding
// travels out of a genuine analysis run attributed to the .latte file. The package shipped inert
// through Tasks 1-2, so "the rule exists and its tests pass" and "the rule runs" are different
// claims and only a spawn settles the second one.
final class LatteFormsSpawnIntegrationTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	public function testBothDiagnosticsFireInARealAnalysisRun(): void
	{
		$projectRoot = dirname(__DIR__, 3);
		$scratch = $this->createScratchDir($projectRoot);
		$srcDir = $scratch . '/src';
		$relSrc = str_replace($projectRoot . '/', '', $srcDir);
		$storeDir = $scratch . '/discovery';
		$tmpDir = $scratch . '/pstmp';

		try {
			// A Form subclass declaring no constructor of its own, exactly as this repo's own forms
			// are: ConstructorFormShapeResolver skips an inherited constructor, while a bare
			// `new Nette\Application\UI\Form()` raises constructor_build and would leave every shape
			// open - the reach cliff the bridge inherits from the Forms extension.
			FileSystem::write(
				$srcDir . '/SpawnForm.php',
				"<?php declare(strict_types = 1);\n\n"
				. "final class SpawnForm extends Nette\\Application\\UI\\Form\n"
				. "{\n\n"
				. "}\n",
			);
			FileSystem::write(
				$srcDir . '/SpawnGrid.php',
				"<?php declare(strict_types = 1);\n\n"
				. "final class SpawnGrid extends Nette\\ComponentModel\\Container\n"
				. "{\n\n"
				. "}\n",
			);
			FileSystem::write(
				$srcDir . '/SpawnFormControl.php',
				"<?php declare(strict_types = 1);\n\n"
				. "final class SpawnFormControl extends Nette\\Application\\UI\\Control\n"
				. "{\n\n"
				. "\tpublic function render(): void\n"
				. "\t{\n"
				. "\t\t\$this->template->setFile(__DIR__ . '/spawn-form.latte');\n"
				. "\t\t\$this->template->render();\n"
				. "\t}\n\n"
				. "\tpublic function createComponentSpawnForm(): SpawnForm\n"
				. "\t{\n"
				. "\t\t\$form = new SpawnForm();\n"
				. "\t\t\$form->addText('name');\n"
				. "\t\t\$form->addSubmit('send');\n\n"
				. "\t\treturn \$form;\n"
				. "\t}\n\n"
				. "\tpublic function createComponentSpawnGrid(): SpawnGrid\n"
				. "\t{\n"
				. "\t\t\$grid = new SpawnGrid();\n\n"
				. "\t\treturn \$grid;\n"
				. "\t}\n\n"
				. "}\n",
			);
			FileSystem::write(
				$srcDir . '/spawn-form.latte',
				"{form spawnForm}\n"
				. "\t<input n:name=\"name\">\n"
				. "\t<input n:name=\"nope\">\n"
				. "{/form}\n"
				. "{form spawnGrid}\n"
				. "{/form}\n",
			);

			// The template->store-class edge must exist before the first parse, exactly as the
			// pre-analysis index build bakes it in (DiscoveryStoreInvalidationTest's note).
			DiscoveryStore::bootstrap($storeDir, ["$relSrc/spawn-form.latte"]);

			// Run 1 links the template to its renderer through the writer; run 2 is the steady state
			// every later run reproduces, and is what this test asserts on - the rule's own reading of
			// the store must not depend on which aggregate rule the finalizer happens to call first.
			$this->messages($projectRoot, $srcDir, $tmpDir, $storeDir);
			$messages = $this->messages($projectRoot, $srcDir, $tmpDir, $storeDir);

			self::assertSame(
				[
					[
						'identifier' => LatteFormsRule::UNKNOWN_CONTROL_IDENTIFIER,
						'file' => "$relSrc/spawn-form.latte",
						'line' => 3,
						'ignorable' => true,
						'message' => "Control 'nope' does not exist on form 'spawnForm' (SpawnFormControl).",
					],
					[
						'identifier' => LatteFormsRule::UNKNOWN_FORM_IDENTIFIER,
						'file' => "$relSrc/spawn-form.latte",
						'line' => 5,
						'ignorable' => true,
						'message' => "Component 'spawnGrid' is not a form (SpawnFormControl).",
					],
				],
				$messages,
			);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	public function testEveryRenderMethodOfAControlLinksItsOwnTemplate(): void
	{
		$projectRoot = dirname(__DIR__, 3);
		$scratch = $this->createScratchDir($projectRoot);
		$srcDir = $scratch . '/src';
		$relSrc = str_replace($projectRoot . '/', '', $srcDir);
		$storeDir = $scratch . '/discovery';
		$tmpDir = $scratch . '/pstmp';

		try {
			FileSystem::write(
				$srcDir . '/TwoForm.php',
				"<?php declare(strict_types = 1);\n\n"
				. "final class TwoForm extends Nette\\Application\\UI\\Form\n"
				. "{\n\n"
				. "}\n",
			);
			FileSystem::write(
				$srcDir . '/TwoTemplatesControl.php',
				"<?php declare(strict_types = 1);\n\n"
				. "final class TwoTemplatesControl extends Nette\\Application\\UI\\Control\n"
				. "{\n\n"
				. "\tpublic function render(): void\n"
				. "\t{\n"
				. "\t\t\$this->template->setFile(__DIR__ . '/two-a.latte');\n"
				. "\t\t\$this->template->render();\n"
				. "\t}\n\n"
				. "\tpublic function renderOther(): void\n"
				. "\t{\n"
				. "\t\t\$this->template->setFile(__DIR__ . '/two-b.latte');\n"
				. "\t\t\$this->template->render();\n"
				. "\t}\n\n"
				. "\tpublic function createComponentTwoForm(): TwoForm\n"
				. "\t{\n"
				. "\t\t\$form = new TwoForm();\n"
				. "\t\t\$form->addText('name');\n\n"
				. "\t\treturn \$form;\n"
				. "\t}\n\n"
				. "}\n",
			);
			$template = "{form twoForm}\n\t<input n:name=\"nope\">\n{/form}\n";
			FileSystem::write($srcDir . '/two-a.latte', $template);
			FileSystem::write($srcDir . '/two-b.latte', $template);

			DiscoveryStore::bootstrap($storeDir, ["$relSrc/two-a.latte", "$relSrc/two-b.latte"]);

			$this->messages($projectRoot, $srcDir, $tmpDir, $storeDir);
			$messages = $this->messages($projectRoot, $srcDir, $tmpDir, $storeDir);
			usort($messages, static fn (array $a, array $b): int => $a['file'] <=> $b['file']);

			$expected = [];
			foreach (['two-a.latte', 'two-b.latte'] as $file) {
				$expected[] = [
					'identifier' => LatteFormsRule::UNKNOWN_CONTROL_IDENTIFIER,
					'file' => "$relSrc/$file",
					'line' => 2,
					'ignorable' => true,
					'message' => "Control 'nope' does not exist on form 'twoForm' (TwoTemplatesControl).",
				];
			}

			self::assertSame($expected, $messages);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	/**
	 * @return list<array{identifier: string, file: string, line: int, ignorable: bool, message: string}>
	 */
	private function messages(string $projectRoot, string $srcDir, string $tmpDir, string $storeDir): array
	{
		$isolated = LattePhpstanConfig::create(
			self::REAL_CONFIG_PATH,
			[$srcDir],
			$tmpDir,
			[
				'orisaiNette.latte.discovery.storePath' => $storeDir,
				'orisaiNette.latte.firstPartyPaths' => [$srcDir],
			],
		);

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

			$identifiers = [
				LatteFormsRule::UNKNOWN_CONTROL_IDENTIFIER,
				LatteFormsRule::UNKNOWN_FORM_IDENTIFIER,
			];

			$messages = [];
			foreach ($decoded['files'] as $file => $fileMessages) {
				foreach ($fileMessages['messages'] as $message) {
					if (!in_array($message['identifier'], $identifiers, true)) {
						continue;
					}

					$messages[] = [
						'identifier' => $message['identifier'],
						'file' => str_replace($projectRoot . '/', '', $file),
						'line' => $message['line'],
						'ignorable' => $message['ignorable'],
						'message' => $message['message'],
					];
				}
			}

			usort($messages, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);

			return $messages;
		} finally {
			$isolated->cleanup();
		}
	}

	private function createScratchDir(string $projectRoot): string
	{
		// var/tmp/, never the system temp dir: ProjectRelativePath::relativize is a bare
		// str_replace($projectRoot . '/', '', $file), so a path outside the project never relativizes
		// and every rel-path lookup in the store and the universe breaks.
		$dir = $projectRoot . '/var/tmp/latte-forms-spawn-test-' . uniqid('', true);
		FileSystem::createDir($dir);

		return $dir;
	}

}
