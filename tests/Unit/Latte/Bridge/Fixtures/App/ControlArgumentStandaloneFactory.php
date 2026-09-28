<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\TemplateFactory;

// The standalone shape reached through the UI\TemplateFactory INTERFACE rather than the bridge
// implementation - the receiver check has to accept both, since the argument the vendor reads is
// declared on the interface.
final class ControlArgumentStandaloneFactory
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
