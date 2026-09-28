<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Template;
use stdClass;

final class RenderSiteNonLiteralArgPresenter
{

	/** @var Template|stdClass */
	public $template;

	public function render(): void
	{
		$this->template->render($this->buildPath());
	}

	private function buildPath(): string
	{
		return 'dynamic.latte';
	}

}
