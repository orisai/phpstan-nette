<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App;

use Nette\Application\Attributes\TemplateVariable;
use Nette\Application\UI\Presenter;

abstract class FactoryVarsTemplateVariableBasePresenter extends Presenter
{

	#[TemplateVariable]
	public array $crumbs = [];

}
