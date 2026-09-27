<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support\InheritedFilterControl;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function PHPStan\dumpType;

/**
 * The corpus construct: a builder hands its form to a protected helper inherited from a parent class
 * in another file, and the helper attaches a replicator. Following it puts `question_filter` into the
 * shape — which is what makes a reference to a name UNDER the replicator resolvable as a known hop
 * rather than reportable as absent.
 */
final class MInheritedHelperReplicatorResolves extends InheritedFilterControl
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('name');
		$this->addFilterDynamicContainer($form);

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getValues(true)); // => array{name: string, question_filter: array<int, array{id: string|null, value: string}>}
		dumpType($this['form']['question_filter']); // => array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{id: string|null, value: string}>
	}

}
