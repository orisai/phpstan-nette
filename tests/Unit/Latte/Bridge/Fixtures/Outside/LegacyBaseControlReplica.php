<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Outside;

use Nette\Application\UI\Control;
use Nette\Bridges\ApplicationLatte\Template;
use function assert;

/**
 * @property-read Template $template
 */
class LegacyBaseControlReplica extends Control
{

	public function createTemplate(?string $class = null): Template
	{
		$template = parent::createTemplate();
		assert($template instanceof Template);

		return $template;
	}

}
