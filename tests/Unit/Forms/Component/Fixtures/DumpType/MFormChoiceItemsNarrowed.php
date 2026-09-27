<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Control\BaseFormControl;
use Nette\Utils\ArrayHash;
use function PHPStan\dumpType;

final class MFormChoiceItemsNarrowed extends BaseFormControl
{

	private const ROLES = ['admin' => 'Admin', 'user' => 'User'];

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addSelect('status', 'Status', ['draft' => 'Draft', 'live' => 'Live']);
		$form->addSelect('role', 'Role', self::ROLES)->setRequired();
		$form->addMultiSelect('tags', 'Tags', ['a' => 'A', 'b' => 'B']);

		$form->onSuccess[] = function (ApplicationForm $form, ArrayHash $values): void {
			dumpType($values); // => Nette\Utils\ArrayHash{status: 'draft'|'live'|null, role: 'admin'|'user', tags: list<'a'|'b'>}
		};

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{status: 'draft'|'live'|null, role: 'admin'|'user'|null, tags: list<'a'|'b'>}
	}

}
