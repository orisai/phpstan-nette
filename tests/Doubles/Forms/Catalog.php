<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use stdClass;

final class Catalog
{

	public function calls(): void
	{
		$f = new ApplicationForm();
		$f->addText('t');
		$f->addPassword('p');
		$f->addTextArea('ta');
		$f->addEmail('e');
		$f->addColor('co');
		$f->addInteger('i');
		$f->addFloat('fl');
		$f->addCheckbox('cb');
		$f->addHidden('h');
		$f->addSelect('s');
		$f->addRadioList('rl');
		$f->addMultiSelect('ms');
		$f->addCheckboxList('cl');
		$f->addUpload('u');
		$f->addMultiUpload('mu');
		$f->addDate('d');
		$f->addTime('tm');
		$f->addDateTime('dt');
		$f->addContainer('c');
		$f->addDynamic('dy', function (): void {
		});
		$f->addSubmit('sb');
		$f->addButton('bt');
		$f->addImageButton('ib');
		$f->addImage();
		$f->addReCaptcha('rc');
		$f->addProtection();
		$f->somethingUnknownAdding('x');
		$o = new stdClass();
		$o->addText('nf');
	}

}
