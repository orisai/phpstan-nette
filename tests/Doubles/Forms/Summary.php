<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Forms\Controls\Checkbox;
use Nette\Forms\Controls\DateTimeControl;
use Nette\Forms\Controls\TextInput;

final class Summary
{

	private const NAME = 'nm';

	private const SUF = 'x';

	public function cases(string $dyn): void
	{
		$f = new ApplicationForm();
		$f->addText('lit');
		$f->addText(self::NAME);
		$f->addText('pre_' . self::SUF);
		$f->addText($dyn);
		$f->addText('nb')->setNullable();
		$f->addDate('dts')->setFormat(DateTimeControl::FormatTimestamp);
		$f->addDate('dok')->setFormat(DateTimeControl::FormatObject);
		$f->addSomethingUnknown('uk');
		$f->removeComponent($f['lit']);
		unset($f['nb']);
		$f['os'] = new TextInput();
		$f->addComponent(new Checkbox(), 'ac');
	}

}
