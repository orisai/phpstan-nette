<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\Filters;

use Nette\Application\UI\Control;
use Nette\Bridges\ApplicationLatte\DefaultTemplate;

/**
 * @property-read DefaultTemplate $template
 */
final class OpenFilterControl extends Control
{

	public function render(string $name): void
	{
		$this->template->addFilter($name, 'strtoupper');
		$this->template->setFile(__DIR__ . '/open.latte');
		$this->template->render();
	}

}
