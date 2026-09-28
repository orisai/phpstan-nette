<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App;

use Nette\Bridges\ApplicationLatte\TemplateFactory;

/**
 * @property-read PairingBaseTemplateReplica $template
 */
final class PairingDualChannelControl
{

	private TemplateFactory $templateFactory;

	public function __construct(TemplateFactory $templateFactory)
	{
		$this->templateFactory = $templateFactory;
	}

	public function renderBell(): void
	{
		$this->templateFactory->createTemplate(null, PairingUnrelatedTemplateReplica::class);
	}

}
