<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;
use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support\MappedFormDto;
use function PHPStan\dumpType;

/**
 * A form-level setMappedType(Dto::class) makes no-arg getValues() return the DTO, both
 * when read cross-scope via $this['form'] and inside an onSuccess closure. getValues(true)
 * and getUntrustedValues() ignore the mapped type (Nette returns array / ArrayHash there).
 */
final class MFormSetMappedTypeGetValues extends BaseFormControl
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->setMappedType(MappedFormDto::class);
		$form->addText('name');
		$form->addInteger('age');

		$form->onSuccess[] = function (ApplicationForm $form): void {
			dumpType($this['form']->getValues()); // => Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support\MappedFormDto
		};

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support\MappedFormDto
		dumpType($this['form']->getValues()->name); // => string
		dumpType($this['form']->getValues()->age); // => int|null
		dumpType($this['form']->getValues(true)); // => array{name: string, age: int|null}
		dumpType($this['form']->getUntrustedValues()); // => Nette\Utils\ArrayHash{name: string, age: int|null}
	}

}
