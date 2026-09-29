<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Control;
use Nette\Application\UI\Template;
use Nette\Bridges\ApplicationLatte\TemplateFactory;

abstract class ControlArgumentNoneChainBase extends Control
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
