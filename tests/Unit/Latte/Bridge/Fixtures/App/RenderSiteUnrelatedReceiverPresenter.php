<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Template;
use stdClass;

final class RenderSiteUnrelatedReceiverPresenter
{

	/** @var Template|stdClass */
	public $template;

	private UnrelatedRenderReceiverFixture $child;

	public function __construct(UnrelatedRenderReceiverFixture $child)
	{
		$this->child = $child;
	}

	public function render(): void
	{
		$this->template->render();
		$this->child->render();
	}

}
