<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Bridges\ApplicationLatte\TemplateFactory;

final class TemplateClassConditionalCreateTemplatePresenter
{

	private TemplateFactory $templateFactory;

	/** @var bool */
	public $flag = false;

	public function __construct(TemplateFactory $templateFactory)
	{
		$this->templateFactory = $templateFactory;
	}

	public function actionDefault(): void
	{
		if ($this->flag) {
			$this->templateFactory->createTemplate(null, TemplateClassChannelTargetOne::class);
		}
	}

}
