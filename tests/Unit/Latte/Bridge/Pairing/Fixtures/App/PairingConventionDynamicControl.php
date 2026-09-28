<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App;

use Nette\Application\UI\Template;
use stdClass;

final class PairingConventionDynamicControl
{

	/** @var Template|stdClass */
	public $template;

	/** @var string */
	public $templateClassName = PairingBaseTemplateReplica::class;

	protected function getTemplateClass(): string
	{
		return $this->templateClassName;
	}

}
