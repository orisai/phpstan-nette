<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\Filters;

use Nette\Application\UI\Control;
use Nette\Bridges\ApplicationLatte\DefaultTemplate;

/**
 * @property-read DefaultTemplate $template
 */
final class FunctionControl extends Control
{

	public function render(string $name): void
	{
		$this->template->addFunction('money', [FilterHelpers::class, 'email']);
		$latte = $this->template->getLatte();
		$latte->addFunction('twice', fn (int $i): int => $i * 2);
		$latte->addFunction($name, 'strtoupper');
		$this->template->setFile(__DIR__ . '/functions.latte');
	}

}
