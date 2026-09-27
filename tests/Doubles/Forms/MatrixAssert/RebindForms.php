<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert;

use Nette\Utils\ArrayHash;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function OriPhpstan\Nette\Forms\Testing\assertComponent;
use function OriPhpstan\Nette\Forms\Testing\assertFormValues;

final class RebindForms
{

	public function foreachRebind(): void
	{
		$form = new ApplicationForm();
		$form->addText('base');
		foreach ($this->formList() as $form) {
			$form->addText('x');
		}
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  x?: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT);
	}

	public function refRebind(ApplicationForm $other): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form =& $other;
		$form->addText('b');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  b: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT);
	}

	public function catchRebind(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		try {
			$this->risky();
		} catch (\Throwable $form) {
			$form->addText('ghost');
		}
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a?: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ghost?: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT);
	}

	/**
	 * @param array<ApplicationForm> $pair
	 */
	public function destructureRebind(array $pair): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		[$form] = $pair;
		$form->addText('b');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  b: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT);
	}

	public function factoryAssignHandler(): void
	{
		$form = $this->createBase();
		$form->addText('name');
		$form->onSuccess[] = function (ApplicationForm $f, ArrayHash $values) use ($form): void {
			assertFormValues($values, 'Nette\Utils\ArrayHash{name: string, ...<mixed>}');
		};
	}

	private function risky(): void
	{
	}

	private function createBase(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('email');

		return $form;
	}

	/**
	 * @return list<ApplicationForm>
	 */
	private function formList(): array
	{
		return [];
	}

}
