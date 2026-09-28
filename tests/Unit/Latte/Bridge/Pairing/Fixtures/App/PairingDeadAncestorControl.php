<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App;

use Nette\Bridges\ApplicationLatte\Template;

class PairingDeadAncestorControl
{

	protected function createTemplate(): Template
	{
		return new PairingUnrelatedTemplateReplica();
	}

}
