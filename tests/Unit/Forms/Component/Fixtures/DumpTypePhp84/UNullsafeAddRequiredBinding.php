<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpTypePhp84;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use Nette\Utils\ArrayHash;
use function PHPStan\dumpType;

final class UNullsafeAddRequiredBinding extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$field = $form?->addText('viaNullsafe');
		$field->setRequired();
		$form->addText('plain');

		$form->onSuccess[] = function (ApplicationForm $form, ArrayHash $values): void {
			dumpType($values); // => Nette\Utils\ArrayHash{viaNullsafe: non-empty-string|null, plain: string}
		};

		return $form;
	}

}
