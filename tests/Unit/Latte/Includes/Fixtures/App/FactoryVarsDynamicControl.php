<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App;

use Nette\Application\UI\Template;
use stdClass;

final class FactoryVarsDynamicControl
{

	/** @var Template|stdClass */
	public $template;

	/** @var string */
	public $templateClassName = FactoryVarsTemplateReplica::class;

	protected function getTemplateClass(): string
	{
		return $this->templateClassName;
	}

}
