<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Configuration;

use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\ScratchProject;
use function sprintf;
use function strlen;
use function strpos;
use function substr;

final class FormsOffSwitchTest extends BaseTestCase
{

	private const PATHS = ['src'];

	private ScratchProject $project;

	protected function setUp(): void
	{
		parent::setUp();
		$this->project = ScratchProject::create('forms-off-switch');
		$this->writeCorpus();
	}

	protected function tearDown(): void
	{
		$this->project->cleanup();
		parent::tearDown();
	}

	public function testEveryGatedServiceReportsWhileEnabled(): void
	{
		$result = $this->project->analyse($this->parameters(true), self::PATHS);
		self::assertSame([], $result['errors'], $result['stderr']);

		self::assertSame($this->expectedWhileEnabled(), $this->findings($result['messages']));
	}

	public function testTheOffSwitchSilencesEveryGatedService(): void
	{
		$result = $this->project->analyse($this->parameters(false), self::PATHS);
		self::assertSame([], $result['errors'], $result['stderr']);

		// The replicator row degrades with the component access it composes on: with Forms off,
		// $this['form']['rows'] is untyped, so createOne() has nothing to type.
		self::assertSame(
			[
				'src/Events.php:13 phpstan.dumpType Dumped type: *ERROR*',
				'src/Rows.php:21 phpstan.dumpType Dumped type: mixed',
			],
			$this->findings($result['messages']),
		);
	}

	/**
	 * @return list<string>
	 */
	private function expectedWhileEnabled(): array
	{
		return [
			'src/Events.php:13 phpstan.dumpType Dumped type: array<int, callable(): mixed>',
			'src/Fields.php:8 orisaiNette.forms.unannotatedRegistrar',
			'src/Rows.php:21 phpstan.dumpType Dumped type: Nette\Forms\Container{name: string}',
			'src/Signup.php:17 orisaiNette.forms.shadowDivergence',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function parameters(bool $formsEnabled): array
	{
		return ConfigurationCorpus::PHPSTAN_NETTE_SWITCHES + [
			'orisaiNette' => [
				'forms' => [
					'enabled' => $formsEnabled,
					'reportUnannotatedRegistrars' => true,
					'internals' => ['indexShadowCompare' => true],
				],
			],
		];
	}

	/**
	 * @param list<array{file: string, line: int, message: string, identifier: string|null}> $messages
	 * @return list<string>
	 */
	private function findings(array $messages): array
	{
		$findings = [];
		foreach ($messages as $message) {
			$identifier = $message['identifier'] ?? '';
			$file = (string) substr($message['file'], strlen($this->project->path('')));
			if ($identifier === 'phpstan.dumpType') {
				$findings[] = sprintf('%s:%d %s %s', $file, $message['line'], $identifier, $message['message']);
			} elseif (strpos($identifier, 'orisaiNette.') === 0) {
				$findings[] = sprintf('%s:%d %s', $file, $message['line'], $identifier);
			}
		}

		return $findings;
	}

	private function writeCorpus(): void
	{
		$this->project->write('src/Fields.php', <<<'PHP'
<?php declare(strict_types = 1);

namespace Corpus;

final class Fields extends \Nette\Forms\Container
{

	public function addPlain(string $name): \Nette\Forms\Controls\TextInput
	{
		return $this->addText($name);
	}

}

PHP);
		$this->project->write('src/Events.php', <<<'PHP'
<?php declare(strict_types = 1);

namespace Corpus;

final class Events
{

	use \Nette\SmartObject;

	public function probe(): void
	{
		// The only reader of $onChange is SmartObjectEventPropertyReflectionExtension.
		\PHPStan\dumpType($this->onChange);
	}

}

PHP);
		$this->project->write('src/RowsForm.php', <<<'PHP'
<?php declare(strict_types = 1);

namespace Corpus;

final class RowsForm extends \Nette\Application\UI\Form
{

	/**
	 * @form-adds $name
	 * @param callable(\Nette\Forms\Container): void $factory
	 */
	public function addDynamic(string $name, callable $factory): \Kdyby\Replicator\Container
	{
		return $this[$name] = new \Kdyby\Replicator\Container($factory);
	}

}

PHP);
		$this->project->write('src/Rows.php', <<<'PHP'
<?php declare(strict_types = 1);

namespace Corpus;

final class Rows extends \Nette\Application\UI\Control
{

	protected function createComponentForm(): RowsForm
	{
		$form = new RowsForm();
		$form->addDynamic('rows', static function (\Nette\Forms\Container $row): void {
			$row->addText('name');
		});

		return $form;
	}

	public function probe(): void
	{
		// createOne() is typed by ReplicatorMethodReturnTypeExtension alone.
		\PHPStan\dumpType($this['form']['rows']->createOne());
	}

}

PHP);
		$this->project->write('src/Signup.php', <<<'PHP'
<?php declare(strict_types = 1);

namespace Corpus;

final class Signup extends \Nette\Application\UI\Control
{

	protected function createComponentForm(): \Nette\Application\UI\Form
	{
		$form = new \Nette\Application\UI\Form();
		$form->addText('email');
		$form->onSuccess[] = [$this, 'formSucceeded'];

		return $form;
	}

	public function formSucceeded(\Nette\Application\UI\Form $form): void
	{
	}

}

PHP);
	}

}
