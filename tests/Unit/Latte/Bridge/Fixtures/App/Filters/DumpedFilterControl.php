<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\Filters;

use Nette\Application\UI\Control;
use Nette\Bridges\ApplicationLatte\DefaultTemplate;

/**
 * @property-read DefaultTemplate $template
 */
final class DumpedFilterControl extends Control
{

	public function render(bool $loud): void
	{
		$this->template->addFilter('mail', [FilterHelpers::class, 'email']);
		if ($loud) {
			$this->template->addFilter('shout', fn (string $s): string => $s);
		}

		$this->template->getLatte()->addFilterLoader(static fn (string $name): ?callable => null);
		$this->template->getLatte()->addFunction('money', [FilterHelpers::class, 'email']);
	}

}
