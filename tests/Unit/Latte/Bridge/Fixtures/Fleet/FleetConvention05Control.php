<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Fleet;

use Nette\Application\UI\Template;
use stdClass;

final class FleetConvention05Control
{

	/** @var Template|stdClass */
	public $template;

	protected function getTemplateClass(): string
	{
		return FleetTemplate05::class;
	}

}
