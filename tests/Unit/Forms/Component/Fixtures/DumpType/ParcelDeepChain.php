<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Nette\Application\UI\Form;
use function PHPStan\dumpType;

final class ParcelDeepChain extends Control
{

	protected function createComponentParcelTrackingForm(): Form
	{
		$form = new Form();
		$address = $form->addContainer('address');
		$address->addText('address_id');

		return $form;
	}

	public function renderRow(): void
	{
		dumpType($this['parcelTrackingForm']['address']['address_id']); // => Nette\Forms\Controls\TextInput
	}

}
