<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\Filters;

use Nette\Application\UI\Control;
use Nette\Bridges\ApplicationLatte\DefaultTemplate;

/**
 * @property-read DefaultTemplate $template
 */
final class FirstClassCallableControl extends Control
{

	private FilterHelpers $helpers;

	public function __construct(FilterHelpers $helpers)
	{
		$this->helpers = $helpers;
	}

	public function render(): void
	{
		$this->template->addFilter('function', strtoupper(...));
		$this->template->addFilter('static', FilterHelpers::email(...));
		$this->template->addFilter('instance', $this->helpers->format(...));
		$this->template->addFilter('own', $this->own(...));
		$this->template->render(__DIR__ . '/fcc.latte');
	}

	private function own(int $i): string
	{
		return (string) $i;
	}

}
