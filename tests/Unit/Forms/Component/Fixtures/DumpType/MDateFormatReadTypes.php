<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;
use Nette\Forms\Controls\DateTimeControl;
use Nette\Utils\ArrayHash;
use function PHPStan\dumpType;

/**
 * setFormat() maps its argument to the field's read type via the @form-read-by-arg
 * metadata on FormModifierCatalog. A bare getValues() keeps the implicit null; inside
 * onSuccess the form is valid, so required fields drop it (int, non-empty-string).
 */
final class MDateFormatReadTypes extends BaseFormControl
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addDate('ts')->setFormat(DateTimeControl::FormatTimestamp);
		$form->addDate('tsReq')->setFormat(DateTimeControl::FormatTimestamp)->setRequired();
		$form->addDate('obj')->setFormat(DateTimeControl::FormatObject);
		$form->addDate('str')->setFormat('Y-m-d');
		$form->addDate('strReq')->setFormat('Y-m-d')->setRequired();

		$form->onSuccess[] = function (ApplicationForm $form, ArrayHash $values): void {
			dumpType($values); // => Nette\Utils\ArrayHash{ts: int|null, tsReq: int, obj: DateTimeImmutable|null, str: string|null, strReq: non-empty-string}
		};

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{ts: int|null, tsReq: int|null, obj: DateTimeImmutable|null, str: string|null, strReq: string|null}
	}

}
