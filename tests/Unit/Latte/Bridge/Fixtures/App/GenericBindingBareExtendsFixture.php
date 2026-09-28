<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Bridges\ApplicationLatte\DefaultTemplate;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Outside\GenericBaseControlReplica;

// Degrade path: an @extends tag is present but carries no generic type argument at all, so the
// resolved surface never gets past the generic bound.
/**
 * @extends GenericBaseControlReplica
 */
final class GenericBindingBareExtendsFixture extends GenericBaseControlReplica
{

	protected function getTemplateClass(): string
	{
		return DefaultTemplate::class;
	}

}
