<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App;

use Nette\Application\Attributes\TemplateVariable;
use Nette\Application\UI\Control;
use Nette\Bridges\ApplicationLatte\DefaultTemplate;

/**
 * @property-read DefaultTemplate $template
 */
final class FactoryVarsTemplateVariableControl extends Control
{

	#[TemplateVariable]
	public string $title = '';

}
