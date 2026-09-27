<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Nette\Application\UI\Form;

/**
 * A project base form the specific subclasses below extend, so the two build-visibility cases differ
 * from the vendor-parent case in exactly one thing: where the parent's declaration lives.
 */
abstract class SpecificProjectBaseForm extends Form
{

	public function __construct()
	{
		parent::__construct();
		$this->addHidden('id');
	}

}
