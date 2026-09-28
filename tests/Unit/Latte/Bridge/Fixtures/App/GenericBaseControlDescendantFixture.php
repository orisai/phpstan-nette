<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Bridges\ApplicationLatte\DefaultTemplate;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Outside\GenericBaseControlReplica;

/**
 * @extends GenericBaseControlReplica<DefaultTemplate>
 */
final class GenericBaseControlDescendantFixture extends GenericBaseControlReplica
{

	protected function getTemplateClass(): string
	{
		return DefaultTemplate::class;
	}

}
