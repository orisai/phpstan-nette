<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class ReplicatorOwnChildResolvesToItsType extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$rep = $form->addDynamic('rows', static function (FormContainer $c): void {
			$c->addText('field');
		});
		$rep->addSubmit('addNode', 'Add');

		return $form;
	}

	public function go(int $i): void
	{
		// A control added straight onto the replicator holder resolves to THAT control, not the
		// row - the false positive this task removes (task 2, test 1).
		dumpType($this['form']['rows']['addNode']); // => Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton

		// A runtime integer offset still resolves to the row's own field (task 1 must not
		// regress - task 2, test 2).
		dumpType($this['form']['rows'][$i]['field']); // => Nette\Forms\Controls\TextInput
	}

}
