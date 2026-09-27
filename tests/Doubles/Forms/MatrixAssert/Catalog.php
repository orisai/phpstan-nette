<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Forms\Controls\DateTimeControl;
use function OriPhpstan\Nette\Forms\Testing\assertComponent;

final class Catalog
{

	public function c01(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	public function c02(): void
	{
		$form = new ApplicationForm();
		$form->addText('a')->setNullable();
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, non-empty-string|null>,
			}
			OUTPUT);
	}

	public function c03(): void
	{
		$form = new ApplicationForm();
		$form->addPassword('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	public function c04(): void
	{
		$form = new ApplicationForm();
		$form->addTextArea('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextArea<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	public function c05(): void
	{
		$form = new ApplicationForm();
		$form->addEmail('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	public function c06(): void
	{
		$form = new ApplicationForm();
		$form->addInteger('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, int|null>,
			}
			OUTPUT);
	}

	public function c07(): void
	{
		$form = new ApplicationForm();
		$form->addFloat('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, float|null>,
			}
			OUTPUT);
	}

	public function c08(): void
	{
		$form = new ApplicationForm();
		$form->addCheckbox('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\Checkbox<bool|float|int|string|null, bool>,
			}
			OUTPUT);
	}

	public function c09(): void
	{
		$form = new ApplicationForm();
		$form->addHidden('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\HiddenField<BackedEnum|bool|float|int|string|Stringable|null, string|null>,
			}
			OUTPUT);
	}

	public function c10(): void
	{
		$form = new ApplicationForm();
		$form->addSelect('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\SelectBox<BackedEnum|int|string|null, int|string|null>,
			}
			OUTPUT);
	}

	public function c11(): void
	{
		$form = new ApplicationForm();
		$form->addRadioList('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\RadioList<BackedEnum|int|string|null, int|string|null>,
			}
			OUTPUT);
	}

	public function c12(): void
	{
		$form = new ApplicationForm();
		$form->addMultiSelect('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\MultiSelectBox<bool|float|int|iterable<BackedEnum|bool|float|int|string|Stringable>|string|null, list<int|string>>,
			}
			OUTPUT);
	}

	public function c13(): void
	{
		$form = new ApplicationForm();
		$form->addCheckboxList('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\CheckboxList<bool|float|int|iterable<BackedEnum|bool|float|int|string|Stringable>|string|null, list<int|string>>,
			}
			OUTPUT);
	}

	public function c14(): void
	{
		$form = new ApplicationForm();
		$form->addUpload('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\UploadControl<*write-only*, Nette\Http\FileUpload|null>,
			}
			OUTPUT);
	}

	public function c15(): void
	{
		$form = new ApplicationForm();
		$form->addMultiUpload('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\UploadControl<*write-only*, list<Nette\Http\FileUpload>|null>,
			}
			OUTPUT);
	}

	public function c16(): void
	{
		$form = new ApplicationForm();
		$form->addDate('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\DateTimeControl<DateTimeInterface|int|string|null, DateTimeImmutable|null>,
			}
			OUTPUT);
	}

	public function c17(): void
	{
		$form = new ApplicationForm();
		$form->addTime('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\DateTimeControl<DateTimeInterface|int|string|null, DateTimeImmutable|null>,
			}
			OUTPUT);
	}

	public function c18(): void
	{
		$form = new ApplicationForm();
		$form->addDateTime('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\DateTimeControl<DateTimeInterface|int|string|null, DateTimeImmutable|null>,
			}
			OUTPUT);
	}

	public function c19(): void
	{
		$form = new ApplicationForm();
		$form->addDate('a')->setFormat(DateTimeControl::FormatTimestamp);
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\DateTimeControl<DateTimeInterface|int|string|null, int|null>,
			}
			OUTPUT);
	}

	public function c20(): void
	{
		$form = new ApplicationForm();
		$form->addColor('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\ColorPicker<string|null, string>,
			}
			OUTPUT);
	}

	public function c21(): void
	{
		$form = new ApplicationForm();
		$form->addSubmit('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton,
			}
			OUTPUT);
	}

	public function c22(): void
	{
		$form = new ApplicationForm();
		$form->addButton('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\Button<*any*, string|null>,
			}
			OUTPUT);
	}

	public function c23(): void
	{
		$form = new ApplicationForm();
		$form->addImageButton('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\ImageButton,
			}
			OUTPUT);
	}

	public function c24(): void
	{
		$form = new ApplicationForm();
		$form->addReCaptcha('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Tests\OriPhpstan\Nette\Doubles\Forms\Control\ReCaptchaField,
			}
			OUTPUT);
	}

	public function c25(): void
	{
		$form = new ApplicationForm();
		$form->addProtection();
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  _token_: Nette\Forms\Controls\CsrfProtection,
			}
			OUTPUT);
	}

	public function c26(): void
	{
		$form = new ApplicationForm();
		assertComponent($form, 'Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{}');
	}


}
