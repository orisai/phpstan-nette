<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App;

use Nette\Application\UI\Presenter;
use Nette\Bridges\ApplicationLatte\DefaultTemplate;
use Nette\Bridges\ApplicationLatte\TemplateFactory;

/**
 * @property-read DefaultTemplate $template
 */
// A PRESENTER creating templates both ways: the inherited vendor createTemplate() its dispatch uses
// and a standalone factory call for a mail body. Being a presenter is not enough - which shape a
// given template came from is still unknown, so nothing may be claimed.
final class FactoryVarsDisagreeingPresenter extends Presenter
{

	private TemplateFactory $templateFactory;

	public function __construct(TemplateFactory $templateFactory)
	{
		parent::__construct();
		$this->templateFactory = $templateFactory;
	}

	public function buildMailTemplate(): void
	{
		$this->templateFactory->createTemplate();
	}

}
