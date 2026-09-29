<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Control;
use Nette\Application\UI\Template;
use Nette\Bridges\ApplicationLatte\TemplateFactory;

// A COMPONENT whose template still gets no control: the createTemplate() override replaces the
// vendor body that would have passed $this, and calls the factory standalone instead. The majority
// shape of this project's own components, and the one that makes "renderer is a component,
// therefore control" wrong.
final class ControlArgumentOverrideFactoryControl extends Control
{

	private TemplateFactory $templateFactory;

	public function __construct(TemplateFactory $templateFactory)
	{
		$this->templateFactory = $templateFactory;
	}

	protected function createTemplate(?string $class = null): Template
	{
		return $this->templateFactory->createTemplate();
	}

}
