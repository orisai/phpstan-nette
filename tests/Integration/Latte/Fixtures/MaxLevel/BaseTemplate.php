<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Fixtures\MaxLevel;

use Nette\Application\UI\Control;
use Nette\Bridges\ApplicationLatte\Template;

/**
 * @template C of Control
 */
abstract class BaseTemplate extends Template
{

	/** @var C */
	public Control $control;

}
