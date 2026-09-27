<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

/**
 * The same shape with a protected build method — a subclass could override it, and the walk still has
 * to answer for the body it can see rather than lose the whole form.
 */
class SpecificProtectedBuildForm extends SpecificProjectBaseForm
{

	public function __construct()
	{
		parent::__construct();
		$this->buildProfileSection();
	}

	protected function buildProfileSection(): void
	{
		$this->addText('nick');
		$this->addCheckbox('public');
	}

}
