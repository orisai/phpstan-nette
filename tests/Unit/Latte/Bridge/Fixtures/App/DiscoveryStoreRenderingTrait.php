<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Bridges\ApplicationLatte\Template;

// A createTemplate() override in a TRAIT, modelled on App\ApiModule\v7Module\Presenters
// \TDescriptionHelper - the shape that made the real corpus grow two index entries the aggregate
// writer never produced. The walk answers for it exactly as it would for a class; only the
// class-kind gate keeps it out of the index.
trait DiscoveryStoreRenderingTrait
{

	protected function createTemplate(): Template
	{
		/** @var Template $template */
		$template = parent::createTemplate();
		$template->addFilter('fixtureOnly', static fn (string $value): string => $value);

		return $template;
	}

}
