<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Bridges\ApplicationLatte\TemplateFactory;

/**
 * @property-read TemplateClassChannelTargetOne $template
 */
final class TemplateClassPhpdocOverridePresenter
{

	private TemplateFactory $templateFactory;

	public function __construct(TemplateFactory $templateFactory)
	{
		$this->templateFactory = $templateFactory;
	}

	public function actionDefault(): void
	{
		$this->templateFactory->createTemplate(null, TemplateClassChannelTargetTwo::class);
	}

}
