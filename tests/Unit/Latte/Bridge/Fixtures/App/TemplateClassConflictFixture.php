<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Bridges\ApplicationLatte\TemplateFactory;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Outside\GenericBaseControlReplica;

/**
 * @extends GenericBaseControlReplica<TemplateClassChannelTargetOne>
 */
final class TemplateClassConflictFixture extends GenericBaseControlReplica
{

	private TemplateFactory $templateFactory;

	public function __construct(TemplateFactory $templateFactory)
	{
		$this->templateFactory = $templateFactory;
	}

	protected function getTemplateClass(): string
	{
		return TemplateClassChannelTargetOne::class;
	}

	public function buildSecondary(): void
	{
		$this->templateFactory->createTemplate(null, TemplateClassChannelTargetTwo::class);
	}

}
