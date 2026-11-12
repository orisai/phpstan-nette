<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

final class DiscoveryDynamicReturnControl extends DiscoveryStaticReturnBase
{

	protected function getFormTemplateFile(): string
	{
		return __DIR__ . '/' . $this->getName() . '.latte';
	}

}
