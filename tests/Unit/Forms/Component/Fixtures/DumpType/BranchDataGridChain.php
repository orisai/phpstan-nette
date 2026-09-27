<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Nette\Application\UI\Form;
use function PHPStan\dumpType;

final class BranchDataGridChain extends Control
{

	protected function createComponentGrid(): Form
	{
		$form = new Form();
		$filter = $form->addContainer('filter');
		$inline = $filter->addContainer('inline_edit');
		$inline->addText('_id');

		return $form;
	}

	public function handleEdit(): void
	{
		dumpType($this['grid']['filter']['inline_edit']['_id']); // => Nette\Forms\Controls\TextInput
	}

}
