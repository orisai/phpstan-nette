<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function PHPStan\dumpType;

/**
 * A helper that provably adds nothing is a PROOF, not a gap: the builder handed its form to code that
 * was read in full and registered no component, so the form is complete and its values array seals.
 *
 * This is the dominant real construct behind an open shape — helpers that only load defaults, fill
 * choice items, or disable an individual control. Refusing to draw the conclusion left every such
 * builder unsealed, and with it every template control reference rendered against it unchecked.
 *
 * The three shapes of "adds nothing" are separated on purpose, because they fail differently if the
 * body is not really being read: a body full of non-registering statements, a body that is genuinely
 * empty, and a body whose only statements sit behind a branch.
 */
final class MEmptyCalleeContributionCloses extends Control
{

	protected function createComponentReadOnlyHelper(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('known');
		$form->addCheckbox('flag');
		$this->loadDefaults($form);

		return $form;
	}

	protected function createComponentEmptyBody(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('known');
		$this->doesNothingAtAll($form);

		return $form;
	}

	protected function createComponentBranchedHelper(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('known');
		$this->maybeRetypes($form);

		return $form;
	}

	private function loadDefaults(ApplicationForm $form): void
	{
		$form->setDefaults(['known' => '']);
	}

	private function doesNothingAtAll(ApplicationForm $form): void
	{
	}

	private function maybeRetypes(ApplicationForm $form): void
	{
		if ($form->isSubmitted() !== false) {
			$form->setDefaults(['known' => 'submitted']);
		}
	}

	public function go(): void
	{
		dumpType($this['readOnlyHelper']->getValues(true)); // => array{known: string, flag: bool}
		dumpType($this['emptyBody']->getValues(true)); // => array{known: string}
		dumpType($this['branchedHelper']->getValues(true)); // => array{known: string}
	}

}
