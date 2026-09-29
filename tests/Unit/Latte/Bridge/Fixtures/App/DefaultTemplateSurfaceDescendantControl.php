<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Outside\DefaultTemplateSurfaceReplica;

final class DefaultTemplateSurfaceDescendantControl extends DefaultTemplateSurfaceReplica
{

	public function render(): void
	{
		$this->template->render(__DIR__ . '/default.latte');
	}

}
