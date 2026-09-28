<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Bridges\ApplicationLatte\TemplateFactory;

final class MailerLikeFixture
{

	private TemplateFactory $templateFactory;

	public function __construct(TemplateFactory $templateFactory)
	{
		$this->templateFactory = $templateFactory;
	}

	public function buildTemplate(): void
	{
		$this->templateFactory->createTemplate();
	}

}
