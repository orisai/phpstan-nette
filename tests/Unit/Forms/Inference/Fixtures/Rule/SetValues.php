<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;
use Nette\Utils\ArrayHash;
use Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Dto;
use Traversable;

final class SetValues
{

	// G9-01: no error (matching shape)
	public function g9_01(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form->addInteger('b');
		$form->setValues(['a' => 'x', 'b' => 1]);
	}

	// G9-02: no error (extra key silently ignored)
	public function g9_02(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form->setValues(['a' => 'x', 'extra' => 1]);
	}

	// G9-03: no error ($erase irrelevant to typing)
	public function g9_03(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form->setDefaults(['a' => 'x'], true);
	}

	// G9-04: no error (nested matching)
	public function g9_04(): void
	{
		$form = new ApplicationForm();
		$c = $form->addContainer('c');
		$c->addText('a');
		$form->setValues(['c' => ['a' => 'x']]);
	}

	// G9-05: no error (stdClass accepted)
	public function g9_05(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form->setValues((object) ['a' => 'x']);
	}

	// G9-06: no error (ArrayHash accepted)
	public function g9_06(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form->setValues(ArrayHash::from(['a' => 'x']));
	}

	// G9-07: no error (typed DTO accepted)
	public function g9_07(): void
	{
		$form = new ApplicationForm();
		$form->addText('name');
		$form->setValues(new Dto());
	}

	// G9-08: no error (Traversable accepted)
	public function g9_08(Traversable $it): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form->setValues($it);
	}

	// G9-09: Form values must be an array or Traversable, string given. [orisaiNette.forms.writeType]
	public function g9_09(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form->setValues('nope');
	}

	// G9-10: no error (opaque silently accepted)
	public function g9_10(iterable $opaque): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form->setValues($opaque);
	}

	// G9-11: no error (replicator setValues not analysable)
	public function g9_11(): void
	{
		$form = new ApplicationForm();
		$form->addDynamic('d', fn (FormContainer $c) => $c->addText('a'));
		$form['d']->setValues([]);
	}

	// G9-12: no error (setDefaults alias of setValues)
	public function g9_12(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form->setDefaults(['a' => 'x']);
	}

}
