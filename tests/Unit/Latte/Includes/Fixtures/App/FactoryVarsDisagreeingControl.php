<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App;

use Nette\Application\UI\Control;
use Nette\Bridges\ApplicationLatte\DefaultTemplate;
use Nette\Bridges\ApplicationLatte\TemplateFactory;

/**
 * @property-read DefaultTemplate $template
 */
// Both creation paths in one component - the inherited vendor body AND a standalone factory call.
// Which one a given template came from is not a per-class fact, so nothing may be claimed.
final class FactoryVarsDisagreeingControl extends Control
{

	private TemplateFactory $templateFactory;

	public function __construct(TemplateFactory $templateFactory)
	{
		$this->templateFactory = $templateFactory;
	}

	public function renderMail(): void
	{
		$this->templateFactory->createTemplate();
	}

}
