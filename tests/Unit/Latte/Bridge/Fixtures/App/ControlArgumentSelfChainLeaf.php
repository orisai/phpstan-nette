<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Template;

// Two app-level createTemplate() overrides deep before the vendor body is reached - the shape has
// to survive the whole delegation chain, because this is how the project's own base controls are
// layered.
final class ControlArgumentSelfChainLeaf extends ControlArgumentSelfChainBase
{

	protected function createTemplate(?string $class = null): Template
	{
		return parent::createTemplate();
	}

}
