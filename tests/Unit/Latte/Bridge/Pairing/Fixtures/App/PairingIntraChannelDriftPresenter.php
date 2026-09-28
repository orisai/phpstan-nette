<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App;

use Nette\Application\UI\Template;

final class PairingIntraChannelDriftPresenter
{

	/** @var bool */
	public $legacy = false;

	protected function createTemplate(): Template
	{
		if ($this->legacy) {
			return new PairingBaseTemplateReplica();
		}

		return new PairingUnrelatedTemplateReplica();
	}

}
