<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\Filters;

use Nette\Application\UI\Control;
use Nette\Bridges\ApplicationLatte\DefaultTemplate;

/**
 * @property-read DefaultTemplate $template
 */
final class UnrelatedFilterControl extends Control
{

	private FilterRegistry $registry;

	public function __construct(FilterRegistry $registry)
	{
		$this->registry = $registry;
	}

	public function render(): void
	{
		$this->registry->addFilter('unrelated', 'strtoupper');
		$this->template->render(__DIR__ . '/unrelated.latte');
	}

}
