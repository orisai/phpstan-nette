<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Latte\Engine;
use Nette\Bridges\ApplicationLatte\DefaultTemplate;

final class KnownTemplateIshFactory
{

	public static function createTemplate(): DefaultTemplate
	{
		return new DefaultTemplate(new Engine());
	}

}
