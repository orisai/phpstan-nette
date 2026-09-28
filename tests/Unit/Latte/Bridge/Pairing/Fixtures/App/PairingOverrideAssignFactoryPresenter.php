<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App;

use Nette\Bridges\ApplicationLatte\TemplateFactory;

/**
 * @property-read PairingChildTemplateReplica $template
 */
final class PairingOverrideAssignFactoryPresenter
{

	private TemplateFactory $templateFactory;

	public function __construct(TemplateFactory $templateFactory)
	{
		$this->templateFactory = $templateFactory;
	}

	protected function createTemplate(): PairingBaseTemplateReplica
	{
		// phpcs:ignore SlevomatCodingStandard.Variables.UselessVariable.UselessVariable
		$template = $this->templateFactory->createTemplate(null, PairingBaseTemplateReplica::class);

		return $template;
	}

}
