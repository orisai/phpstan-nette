<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App;

use Nette\Application\UI\Template;
use stdClass;

final class PairingConventionDelegatingControl
{

	/** @var Template|stdClass */
	public $template;

	public function formatTemplateClass(): string
	{
		return $this->getTemplateClass();
	}

	protected function getTemplateClass(): string
	{
		return PairingBaseTemplateReplica::class;
	}

}
