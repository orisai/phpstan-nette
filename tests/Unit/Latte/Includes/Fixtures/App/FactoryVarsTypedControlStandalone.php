<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App;

use Nette\Bridges\ApplicationLatte\TemplateFactory;

/**
 * @property-read FactoryVarsTypedControlTemplateReplica $template
 */
final class FactoryVarsTypedControlStandalone
{

	private TemplateFactory $templateFactory;

	public function __construct(TemplateFactory $templateFactory)
	{
		$this->templateFactory = $templateFactory;
	}

	public function build(): void
	{
		$this->templateFactory->createTemplate();
	}

}
