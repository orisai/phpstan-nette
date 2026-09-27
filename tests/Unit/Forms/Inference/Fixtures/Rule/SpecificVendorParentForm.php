<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Nette\Application\UI\Form;

/**
 * Only the subclass is ours: the parent form lives OUTSIDE the analysed paths, in nette/application.
 * Every registration inside the build method is a vendor add* call on a vendor-declared receiver.
 */
final class SpecificVendorParentForm extends Form
{

	public function __construct()
	{
		parent::__construct();
		$this->buildSection();
	}

	private function buildSection(): void
	{
		$this->addText('street');
		$this->addInteger('zip');
	}

}
