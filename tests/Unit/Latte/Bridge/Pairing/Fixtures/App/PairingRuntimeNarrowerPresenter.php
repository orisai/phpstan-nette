<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App;

final class PairingRuntimeNarrowerPresenter
{

	protected function createTemplate(): PairingBaseTemplateReplica
	{
		return new PairingChildTemplateReplica();
	}

}
