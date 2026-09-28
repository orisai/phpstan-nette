<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Bridges\ApplicationLatte\TemplateFactory;

final class TemplateFactoryProvenanceReceiverPresenter
{

	/** @var mixed */
	public $template;

	private TemplateFactory $templateFactory;

	public function __construct(TemplateFactory $templateFactory)
	{
		$this->templateFactory = $templateFactory;
	}

	public function actionDefault(): void
	{
		$mailerTpl = $this->templateFactory->createTemplate();
		$mailerTpl->subject = 'hi';
	}

}
