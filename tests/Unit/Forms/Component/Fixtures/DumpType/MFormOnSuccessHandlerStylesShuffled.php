<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;
use Nette\Forms\Form as NetteForm;
use function PHPStan\dumpType;

/**
 * The layout-permutation twin of MFormOnSuccessHandlerStyles: the array-callable handler method is
 * declared BEFORE the createComponent* method that registers it, the reverse of the sibling's order.
 * The store→read seam made the array-callable style order-sensitive (a handler analysed before its
 * registration was collected missed the shape); the index folds every registration up front, so the
 * dumped types are byte-identical to the sibling's regardless of method order — the seam's death
 * certificate at fixture scale.
 */
final class MFormOnSuccessHandlerStylesShuffled extends BaseFormControl
{

	public function handleSuccess(ApplicationForm $form): void
	{
		dumpType($form->getValues()); // => Nette\Utils\ArrayHash{name: non-empty-string}
	}

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

}
