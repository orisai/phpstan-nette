<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App;

use Nette\Application\UI\Control;
use Nette\Application\UI\Template;
use Nette\Bridges\ApplicationLatte\DefaultTemplate;
use Nette\Bridges\ApplicationLatte\TemplateFactory;

/**
 * @property-read DefaultTemplate $template
 */
// A component whose createTemplate() override bypasses the vendor body: the factory gets no
// control, so neither variable is WRITTEN - and both are still exported, because the vendor
// declares them untyped and an untyped property keeps its implicit null.
final class FactoryVarsStandaloneControl extends Control
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
