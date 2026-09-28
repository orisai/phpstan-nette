<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App;

/**
 * @property-read PairingBaseTemplateReplica $template
 */
final class PairingConventionNarrowerControl
{

	protected function getTemplateClass(): string
	{
		return PairingChildTemplateReplica::class;
	}

}
