<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

// Replicates app/presenters/UsersPresenter::createComponentEmployerAdd: the class that instantiates
// a control reaches into its form and adds a field. The access root is a local, so no syntax names
// the owner - every component called employerForm is a candidate.
final class OutsideMutator
{

	public function createComponentEmployerAdd(): ExternallyMutatedRenderer
	{
		$control = new ExternallyMutatedRenderer();
		$control['employerForm']->addHidden('user_employer_id');

		return $control;
	}

}
