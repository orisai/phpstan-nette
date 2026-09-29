<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\LatteForms;

use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function dirname;
use function str_replace;
use function strlen;
use function substr_compare;
use function uniqid;
use function usort;
use const PHP_BINARY;

final class FormMacroTypingSpawnIntegrationTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	private const TEMPLATE = 'typing-form.latte';

	private const SHARED_TEMPLATE = 'shared-form.latte';

	public function testControlsAreTypedFromTheBuilderAndFollowItsEdits(): void
	{
		$projectRoot = dirname(__DIR__, 3);
		$scratch = $this->createScratchDir($projectRoot);
		$srcDir = $scratch . '/src';
		$relSrc = str_replace($projectRoot . '/', '', $srcDir);
		$storeDir = $scratch . '/discovery';
		$tmpDir = $scratch . '/pstmp';

		try {
			$this->writeCorpus($srcDir, 'addCheckboxList(\'years\', \'Years\', [2024 => \'2024\'])');
			DiscoveryStore::bootstrap($storeDir, ["$relSrc/" . self::TEMPLATE, "$relSrc/" . self::SHARED_TEMPLATE]);

			// Run 1 links the templates to their renderers through the writer (the store's own one-run
			// lag, exactly as LatteFormsSpawnIntegrationTest treats it); runs 2 and 3 are the steady
			// state and must agree with each other - a difference there is a determinism defect.
			$this->messages($projectRoot, $srcDir, $tmpDir, $storeDir, self::TEMPLATE);
			$second = $this->messages($projectRoot, $srcDir, $tmpDir, $storeDir, self::TEMPLATE);
			$third = $this->messages($projectRoot, $srcDir, $tmpDir, $storeDir, self::TEMPLATE);

			self::assertSame($second, $third, 'steady-state runs must agree');
			self::assertSame(
				[
					[
						'line' => 5,
						'message' => 'Call to an undefined method Nette\Forms\Controls\TextInput::getItems().',
					],
				],
				$third,
			);

			// The builder changes years to a text input: the foreach line now misuses the control
			// too, the same way the unit-level decision would. Two runs, because a body-only builder
			// edit reaches the template through the rewritten store one run later.
			$this->writeCorpus($srcDir, 'addText(\'years\')');
			$this->messages($projectRoot, $srcDir, $tmpDir, $storeDir, self::TEMPLATE);
			$afterEdit = $this->messages($projectRoot, $srcDir, $tmpDir, $storeDir, self::TEMPLATE);

			self::assertSame(
				[
					[
						'line' => 2,
						'message' => 'Call to an undefined method Nette\Forms\Controls\TextInput::getItems().',
					],
					[
						'line' => 3,
						'message' => 'Method Nette\Forms\Controls\BaseControl::getControlPart() invoked with 1 parameter, 0 required.',
					],
					[
						'line' => 3,
						'message' => 'Method Nette\Forms\Controls\BaseControl::getLabelPart() invoked with 1 parameter, 0 required.',
					],
					[
						'line' => 5,
						'message' => 'Call to an undefined method Nette\Forms\Controls\TextInput::getItems().',
					],
					[
						'line' => 7,
						'message' => 'Call to an undefined method Nette\Forms\Controls\CheckboxList|Nette\Forms\Controls\TextInput::getItems().',
					],
				],
				$afterEdit,
			);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	// The shared template is rendered by two controls whose `typingForm` builders disagree on
	// `years` (checkbox list vs text input). The bridge must keep the eliminator's wide types there:
	// the macro stays BaseControl (no getControlPart($key)), the offset stays IComponent.
	public function testSharedTemplateWithDisagreeingRenderersKeepsTheWideTypes(): void
	{
		$projectRoot = dirname(__DIR__, 3);
		$scratch = $this->createScratchDir($projectRoot);
		$srcDir = $scratch . '/src';
		$relSrc = str_replace($projectRoot . '/', '', $srcDir);
		$storeDir = $scratch . '/discovery';
		$tmpDir = $scratch . '/pstmp';

		try {
			$this->writeCorpus($srcDir, 'addCheckboxList(\'years\', \'Years\', [2024 => \'2024\'])');
			DiscoveryStore::bootstrap($storeDir, ["$relSrc/" . self::TEMPLATE, "$relSrc/" . self::SHARED_TEMPLATE]);

			$this->messages($projectRoot, $srcDir, $tmpDir, $storeDir, self::SHARED_TEMPLATE);
			$steady = $this->messages($projectRoot, $srcDir, $tmpDir, $storeDir, self::SHARED_TEMPLATE);

			self::assertSame(
				[
					[
						'line' => 2,
						'message' => 'Method Nette\Forms\Controls\BaseControl::getControlPart() invoked with 1 parameter, 0 required.',
					],
					[
						'line' => 3,
						'message' => 'Call to an undefined method Nette\ComponentModel\IComponent::getItems().',
					],
					[
						'line' => 4,
						'message' => 'Method Nette\Forms\Controls\Checkbox::getControlPart() invoked with 1 parameter, 0 required.',
					],
				],
				$steady,
			);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	// Discovery links one template per control (its class-level setFile() writes share one bucket),
	// so the shared template gets its own renderer class next to the disagreeing one.
	private function writeCorpus(string $srcDir, string $yearsBuilderCall): void
	{
		FileSystem::write(
			$srcDir . '/TypingForm.php',
			"<?php declare(strict_types = 1);\n\n"
			. "final class TypingForm extends Nette\\Application\\UI\\Form\n"
			. "{\n\n"
			. "}\n",
		);
		FileSystem::write(
			$srcDir . '/TypingFormControl.php',
			"<?php declare(strict_types = 1);\n\n"
			. "final class TypingFormControl extends Nette\\Application\\UI\\Control\n"
			. "{\n\n"
			. "\tpublic function render(): void\n"
			. "\t{\n"
			. "\t\t\$this->template->setFile(__DIR__ . '/" . self::TEMPLATE . "');\n"
			. "\t\t\$this->template->render();\n"
			. "\t}\n\n"
			. "\tpublic function createComponentTypingForm(): TypingForm\n"
			. "\t{\n"
			. "\t\t\$form = new TypingForm();\n"
			. "\t\t\$form->$yearsBuilderCall;\n"
			. "\t\t\$form->addCheckboxList('years2', 'Years 2', [2025 => '2025']);\n"
			. "\t\t\$form->addText('name');\n"
			. "\t\t\$form->addSubmit('send');\n"
			. "\t\t\$form->addProtection();\n\n"
			. "\t\treturn \$form;\n"
			. "\t}\n\n"
			. "}\n",
		);
		FileSystem::write(
			$srcDir . '/SharedTypingFormControl.php',
			"<?php declare(strict_types = 1);\n\n"
			. "final class SharedTypingFormControl extends Nette\\Application\\UI\\Control\n"
			. "{\n\n"
			. "\tpublic function render(): void\n"
			. "\t{\n"
			. "\t\t\$this->template->setFile(__DIR__ . '/" . self::SHARED_TEMPLATE . "');\n"
			. "\t\t\$this->template->render();\n"
			. "\t}\n\n"
			. "\tpublic function createComponentTypingForm(): TypingForm\n"
			. "\t{\n"
			. "\t\t\$form = new TypingForm();\n"
			. "\t\t\$form->$yearsBuilderCall;\n"
			. "\t\t\$form->addCheckbox('agree');\n"
			. "\t\t\$form->addCheckboxList('years2', 'Years 2', [2025 => '2025']);\n"
			. "\t\t\$form->addText('name');\n"
			. "\t\t\$form->addSubmit('send');\n\n"
			. "\t\treturn \$form;\n"
			. "\t}\n\n"
			. "}\n",
		);
		FileSystem::write(
			$srcDir . '/OtherTypingFormControl.php',
			"<?php declare(strict_types = 1);\n\n"
			. "final class OtherTypingFormControl extends Nette\\Application\\UI\\Control\n"
			. "{\n\n"
			. "\tpublic function render(): void\n"
			. "\t{\n"
			. "\t\t\$this->template->setFile(__DIR__ . '/" . self::SHARED_TEMPLATE . "');\n"
			. "\t\t\$this->template->render();\n"
			. "\t}\n\n"
			. "\tpublic function createComponentTypingForm(): TypingForm\n"
			. "\t{\n"
			. "\t\t\$form = new TypingForm();\n"
			. "\t\t\$form->addText('years');\n"
			. "\t\t\$form->addCheckbox('agree');\n"
			. "\t\t\$form->addSubmit('send');\n\n"
			. "\t\treturn \$form;\n"
			. "\t}\n\n"
			. "}\n",
		);
		FileSystem::write(
			$srcDir . '/' . self::TEMPLATE,
			"{form typingForm}\n"
			. "\t{foreach \$form['years']->getItems() as \$key => \$label}\n"
			. "\t\t<label n:name=\"years:\$key\"><input n:name=\"years:\$key\"> {\$label}</label>\n"
			. "\t{/foreach}\n"
			. "\t{var \$broken = \$form['name']->getItems()}\n"
			. "\t{input name}\n"
			. "\t{foreach ['years', 'years2'] as \$n}{var \$items = \$form[\$n]->getItems()}{/foreach}\n"
			. "\t{var \$token = \$form['_token_']->getControl()}\n"
			. "\t{label name}L{/label}\n"
			. "{/form}\n",
		);
		FileSystem::write(
			$srcDir . '/' . self::SHARED_TEMPLATE,
			"{form typingForm}\n"
			. "\t{input years:1}\n"
			. "\t{var \$items = \$form['years']->getItems()}\n"
			. "\t{input agree:1}\n"
			. "{/form}\n",
		);
	}

	/**
	 * @return list<array{line: int, message: string}>
	 */
	private function messages(
		string $projectRoot,
		string $srcDir,
		string $tmpDir,
		string $storeDir,
		string $template
	): array
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

			$messages = [];
			foreach ($decoded['files'] as $file => $fileMessages) {
				if (substr_compare($file, '/' . $template, -strlen('/' . $template)) !== 0) {
					continue;
				}

				// Everything but the one vendor deprecation: nette/forms 3.3 deprecates CsrfProtection,
				// which the typed $form['_token_'] read then reports.
				foreach ($fileMessages['messages'] as $message) {
					if ($message['identifier'] === 'method.deprecatedClass') {
						continue;
					}

					$messages[] = [
						'line' => $message['line'],
						'message' => $message['message'],
					];
				}
			}

			usort(
				$messages,
				static fn (array $a, array $b): int => [$a['line'], $a['message']] <=> [$b['line'], $b['message']],
			);

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
