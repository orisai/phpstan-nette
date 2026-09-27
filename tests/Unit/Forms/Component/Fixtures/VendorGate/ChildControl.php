<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\VendorGate;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function PHPStan\dumpType;

final class ChildControl extends BaseControl
{

	protected function formSucceeded(ApplicationForm $form): void
	{
		dumpType($form->getComponent('note'));
	}

}
