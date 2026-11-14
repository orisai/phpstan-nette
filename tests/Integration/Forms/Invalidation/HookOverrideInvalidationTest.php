<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Forms\Invalidation;

use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;

// Group H - a base-class builder calls an abstract hook the asking subclass's parent overrides. The
// consumer is a further subclass in its own file, so only the walk recording the override's file
// re-analyses it after a body-only edit of the override.
final class HookOverrideInvalidationTest extends FormsInvalidationMatrixCase
{

	public function testBodyEditInTheOverrideOfAnAbstractHook(): void
	{
		$this->assertScenario(
			'h1-hook-override-body',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						'HookChild.php',
						$this->hookChildSource("\t\t\$form->addCheckbox('hooked');\n"),
					),
					'expect' => [$this->dump('early: string, hooked: bool')],
					'reanalysed' => ['HookChild.php', 'HookConsumer.php'],
				],
			],
			null,
			[$this->dump('early: string, hooked: string')],
		);
	}

	protected function seed(InvalidationScenario $scenario): void
	{
		$scenario->write('HookBase.php', $this->hookBaseSource());
		$scenario->write('HookChild.php', $this->hookChildSource());
		$scenario->write('HookConsumer.php', $this->hookConsumerSource());
	}

	private function dump(string $shape): string
	{
		return $this->valuesDump(10, $shape, 'HookConsumer.php');
	}

	private function hookBaseSource(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

use Nette\Application\UI\Form;

abstract class HookBase extends \Nette\Application\UI\Control
{

	protected function createComponentForm(): Form
	{
		$form = new Form();
		$form->addText('early');
		$this->buildFields($form);

		return $form;
	}

	abstract protected function buildFields(Form $form): void;

}

PHP;
	}

	private function hookChildSource(string $body = "\t\t\$form->addText('hooked');\n"): string
	{
		return <<<PHP
<?php declare(strict_types = 1);

use Nette\Application\UI\Form;

class HookChild extends HookBase
{

	protected function buildFields(Form \$form): void
	{
$body	}

}

PHP;
	}

	private function hookConsumerSource(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

use function OriPhpstan\Nette\Forms\Testing\dumpFormValues;

final class HookConsumer extends HookChild
{

	public function probe(): void
	{
		dumpFormValues($this['form']);
	}

}

PHP;
	}

}
