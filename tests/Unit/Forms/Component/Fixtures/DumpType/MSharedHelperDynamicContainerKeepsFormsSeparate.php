<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;
use function PHPStan\dumpType;

/**
 * The dynamic-container analogue of MSharedHelperKeepsFormsSeparate: the shared helper adds a
 * REPLICATOR rather than a control, which is the construct the real corpus routes two builders
 * through. Each form gets its own field plus the replicator, and neither gets the other's field.
 *
 * A replicator's own children stay unknown, but that opens the REPLICATOR's inner shape and not the
 * FORM's: each form-level shape is still closed, so the other form's field is proven absent and reads
 * *ERROR* with one orisai.nette.forms.noSuchComponent, exactly as in the plain-control sibling.
 */
final class MSharedHelperDynamicContainerKeepsFormsSeparate extends Control
{

	protected function createComponentA(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('aOnly');
		$this->addFilter($form);

		return $form;
	}

	protected function createComponentB(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addInteger('bOnly');
		$this->addFilter($form);

		return $form;
	}

	private function addFilter(ApplicationForm $form): void
	{
		$form->addDynamic('question_filter', static function (FormContainer $container): void {
			$container->addHidden('id');
			$container->addText('value');
		}, 0);
	}

	public function go(): void
	{
		dumpType($this['a']->getValues(true)); // => array{aOnly: string, question_filter: array<int, array{id: string|null, value: string}>}
		dumpType($this['b']->getValues(true)); // => array{bOnly: int|null, question_filter: array<int, array{id: string|null, value: string}>}
		dumpType($this['a']['bOnly']); // => *ERROR*
		dumpType($this['b']['aOnly']); // => *ERROR*
	}

}
