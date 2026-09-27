<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support;

use Nette\Application\UI\Control;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;

/**
 * A protected builder helper inherited from another FILE — the shape the real corpus's
 * addFilterDynamicContainer() has. Following it needs the callee's body, which the default analysis
 * parser strips for files outside the CLI-narrowed set; the rich parser is what makes it readable.
 */
abstract class InheritedFilterControl extends Control
{

	protected function addFilterDynamicContainer(ApplicationForm $form): void
	{
		$filter = $form->addDynamic('question_filter', static function (FormContainer $container): void {
			$container->addHidden('id');
			$container->addText('value');
		}, 0);
		$filter->addSubmit('addNode', 'add');
	}

}
