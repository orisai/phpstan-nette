<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App;

/**
 * @property-read PairingChildTemplateReplica $template
 */
final class PairingConventionDriftControl
{

	protected function getTemplateClass(): string
	{
		return PairingUnrelatedTemplateReplica::class;
	}

}
