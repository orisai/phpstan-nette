<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\Filters;

use Nette\Application\UI\Control;
use Nette\Bridges\ApplicationLatte\DefaultTemplate;

/**
 * @property-read DefaultTemplate $template
 */
final class LoaderFilterControl extends Control
{

	public function render(): void
	{
		$this->template->getLatte()->addFilterLoader(static fn (string $name): ?callable => null);
		$this->template->render(__DIR__ . '/loader.latte');
	}

}
