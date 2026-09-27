<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;
use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;
use function PHPStan\dumpType;

/**
 * B6f Part 2 modelled a replicator's getValues() collection as an ArrayHash; this pins the
 * FILLED read-type (post isValid()) for a required field inside that collection — the channel
 * the shadow comparator never compared (it only ever exercises the unfilled default context).
 */
final class MFormReplicatorFilledAfterIsValid extends BaseFormControl
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addDynamic('items', static function (FormContainer $item): void {
			$item->addText('label')->setRequired();
		});

		return $form;
	}

	public function go(): void
	{
		if ($this['form']->isValid()) {
			dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{items: Nette\Utils\ArrayHash<Nette\Utils\ArrayHash{label: non-empty-string}>}
		}

		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{items: Nette\Utils\ArrayHash<Nette\Utils\ArrayHash{label: string}>}
	}

}
