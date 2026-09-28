<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App;

final class PairingAgreeExactPresenter
{

	protected function createTemplate(): PairingBaseTemplateReplica
	{
		return new PairingBaseTemplateReplica();
	}

}
