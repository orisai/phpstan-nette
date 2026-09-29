<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Control;
use Nette\Application\UI\Template;
use Nette\Bridges\ApplicationLatte\TemplateFactory;

// The same override, handing the factory its own instance explicitly - the vendor body's own
// argument, written out at the call site. The inherited rung is off here (the override replaced
// it), so only the walked argument can answer.
final class ControlArgumentExplicitThisControl extends Control
{

	private TemplateFactory $templateFactory;

	public function __construct(TemplateFactory $templateFactory)
	{
		$this->templateFactory = $templateFactory;
	}

	protected function createTemplate(?string $class = null): Template
	{
		return $this->templateFactory->createTemplate($this);
	}

}
