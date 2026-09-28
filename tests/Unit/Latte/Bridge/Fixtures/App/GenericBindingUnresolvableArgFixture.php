<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Bridges\ApplicationLatte\DefaultTemplate;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Outside\GenericBaseControlReplica;

// Degrade path: the generic type argument itself does not resolve to any known class.
/**
 * @extends GenericBaseControlReplica<TemplateClassChannelDoesNotExist>
 */
final class GenericBindingUnresolvableArgFixture extends GenericBaseControlReplica
{

	protected function getTemplateClass(): string
	{
		return DefaultTemplate::class;
	}

}
