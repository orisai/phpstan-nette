<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\Filters;

use Nette\Application\UI\Control;
use Nette\Bridges\ApplicationLatte\DefaultTemplate;

/**
 * @property-read DefaultTemplate $template
 */
final class RenderFileControl extends Control
{

	public function render(): void
	{
		$this->template->render(__DIR__ . '/rendered.latte', ['title' => 'x', 'count' => 1]);
	}

	public function renderFragment(): string
	{
		return $this->template->renderToString(__DIR__ . '/fragment.latte');
	}

}
