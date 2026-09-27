<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;
use Nette\Forms\Form as NetteForm;
use function PHPStan\dumpType;

/**
 * Every onSuccess handler style resolves to the form it was registered on, and the
 * form is valid there, so getValues() is the filled shape (required fields narrow to
 * non-empty) regardless of how the handler references the form.
 */
final class MFormOnSuccessHandlerStyles extends BaseFormControl
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('name')->setRequired();

		// 1. array callable [$this, 'method'] — the form is the handler's parameter
		$form->onSuccess[] = [$this, 'handleSuccess'];

		// 2. closure capturing the local form via use()
		$form->onSuccess[] = function () use ($form): void {
			dumpType($form->getValues()); // => Nette\Utils\ArrayHash{name: non-empty-string}
		};

		// 3. arrow function capturing the local form
		$form->onSuccess[] = fn () => dumpType($form->getValues()); // => Nette\Utils\ArrayHash{name: non-empty-string}

		// 4. closure with a typed form parameter
		$form->onSuccess[] = function (ApplicationForm $form): void {
			dumpType($form->getValues()); // => Nette\Utils\ArrayHash{name: non-empty-string}
		};

		// 5. closure with a base-class form parameter (the declared type is irrelevant)
		$form->onSuccess[] = function (NetteForm $form): void {
			dumpType($form->getValues()); // => Nette\Utils\ArrayHash{name: non-empty-string}
		};

		return $form;
	}

	public function handleSuccess(ApplicationForm $form): void
	{
		dumpType($form->getValues()); // => Nette\Utils\ArrayHash{name: non-empty-string}
	}

}
