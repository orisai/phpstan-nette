<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Outside;

use Nette\Application\UI\Control;
use Nette\Bridges\ApplicationLatte\Template;
use function assert;

/**
 * @property-read Template $template
 */
abstract class NamespacedBaseControlReplica extends Control
{

	public function createTemplate(): Template
	{
		$template = parent::createTemplate();
		assert($template instanceof Template);

		return $template;
	}

}
