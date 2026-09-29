<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Outside;

use Nette\Application\UI\Control;
use Nette\Application\UI\Template;

/**
 * @template T_Template of Template
 *
 * @property-read T_Template $template
 */
abstract class GenericBaseControlReplica extends Control
{

	/**
	 * @return T_Template
	 */
	protected function createTemplate(?string $class = null): Template
	{
		return parent::createTemplate();
	}

	/**
	 * @return class-string<T_Template>
	 */
	abstract protected function getTemplateClass(): string;

}
