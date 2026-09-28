<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App;

/**
 * @property-read PairingChildTemplateReplica $template
 */
final class PairingNoAncestryPresenter
{

	protected function createTemplate(): PairingUnrelatedTemplateReplica
	{
		return new PairingUnrelatedTemplateReplica();
	}

}
