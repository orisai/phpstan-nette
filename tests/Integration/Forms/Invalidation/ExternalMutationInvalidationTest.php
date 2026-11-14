<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Forms\Invalidation;

use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;

// Group I - a class other than the builder adds a field to the form through a component access. The
// consumer reads the form in the builder's own file, so only the fold recording the mutating file
// re-analyses it after a body-only edit there.
final class ExternalMutationInvalidationTest extends FormsInvalidationMatrixCase
{

	public function testBodyEditInTheClassMutatingTheForm(): void
	{
		$this->assertScenario(
			'i1-external-mutation-body',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						'MutatingPresenter.php',
						$this->mutatingSource("\t\t\$control['form']->addCheckbox('extra');\n"),
					),
					'expect' => [$this->dump('own: string, extra: bool|null')],
					'reanalysed' => ['MutatedControl.php', 'MutatingPresenter.php'],
				],
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						'MutatingPresenter.php',
						$this->mutatingSource(''),
					),
					'expect' => [$this->dump('own: string')],
				],
			],
			null,
			[$this->dump('own: string, extra: string|null')],
		);
	}

	protected function seed(InvalidationScenario $scenario): void
	{
		$scenario->write('MutatedControl.php', $this->mutatedSource());
		$scenario->write('MutatingPresenter.php', $this->mutatingSource());
	}

	private function dump(string $shape): string
	{
		return $this->valuesDump(10, $shape, 'MutatedControl.php');
	}

	private function mutatedSource(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

use function OriPhpstan\Nette\Forms\Testing\dumpFormValues;

final class MutatedControl extends \Nette\Application\UI\Control
{

	public function probe(): void
	{
		dumpFormValues($this['form']);
	}

	protected function createComponentForm(): \Nette\Application\UI\Form
	{
		$form = new \Nette\Application\UI\Form();
		$form->addText('own');

		return $form;
	}

}

PHP;
	}

	private function mutatingSource(string $body = "\t\t\$control['form']->addText('extra');\n"): string
	{
		return <<<PHP
<?php declare(strict_types = 1);

final class MutatingPresenter extends \Nette\Application\UI\Presenter
{

	protected function createComponentCtrl(): MutatedControl
	{
		\$control = new MutatedControl();
$body
		return \$control;
	}

}

PHP;
	}

}
