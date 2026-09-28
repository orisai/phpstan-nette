<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App;

use Nette\Bridges\ApplicationLatte\TemplateFactory;

/**
 * @property-read PairingChildTemplateReplica $template
 */
final class PairingOverrideReturnFactoryPresenter
{

	private TemplateFactory $templateFactory;

	public function __construct(TemplateFactory $templateFactory)
	{
		$this->templateFactory = $templateFactory;
	}

	protected function createTemplate(): PairingBaseTemplateReplica
	{
		return $this->templateFactory->createTemplate(null, PairingBaseTemplateReplica::class);
	}

}
