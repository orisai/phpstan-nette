<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App;

use Nette\Application\UI\Template;
use stdClass;

final class PairingConventionIntraChannelControl
{

	/** @var Template|stdClass */
	public $template;

	/** @var bool */
	public $legacy = false;

	protected function getTemplateClass(): string
	{
		if ($this->legacy) {
			return PairingBaseTemplateReplica::class;
		}

		return PairingUnrelatedTemplateReplica::class;
	}

}
