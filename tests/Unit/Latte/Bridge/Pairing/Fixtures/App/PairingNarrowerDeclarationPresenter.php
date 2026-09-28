<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App;

/**
 * @property-read PairingChildTemplateReplica $template
 */
final class PairingNarrowerDeclarationPresenter
{

	protected function createTemplate(): PairingBaseTemplateReplica
	{
		return new PairingBaseTemplateReplica();
	}

}
