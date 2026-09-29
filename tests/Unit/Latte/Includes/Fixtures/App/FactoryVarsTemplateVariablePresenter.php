<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App;

use Nette\Application\Attributes\TemplateVariable;
use Nette\Bridges\ApplicationLatte\DefaultTemplate;

/**
 * @property-read DefaultTemplate $template
 */
final class FactoryVarsTemplateVariablePresenter extends FactoryVarsTemplateVariableBasePresenter
{

	#[TemplateVariable]
	public string $title;

	/** @var list<int> */
	#[TemplateVariable]
	public ?array $ids = null;

	public string $unmarked = '';

	#[TemplateVariable]
	protected string $hidden = '';

	#[TemplateVariable]
	public static string $shared = '';

}
