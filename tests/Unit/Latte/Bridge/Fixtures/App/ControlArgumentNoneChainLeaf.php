<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Template;

// The same two-deep delegation chain, ending in a base that bypasses the vendor body - so the leaf
// inherits NO control, however component-shaped it looks.
final class ControlArgumentNoneChainLeaf extends ControlArgumentNoneChainBase
{

	protected function createTemplate(?string $class = null): Template
	{
		return parent::createTemplate();
	}

}
