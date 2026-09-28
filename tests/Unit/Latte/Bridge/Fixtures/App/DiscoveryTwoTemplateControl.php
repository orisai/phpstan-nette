<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Control;

// Two independent render entry points, each writing its own template file: both files are
// genuinely rendered, so each method's own write is a CHOSEN candidate.
final class DiscoveryTwoTemplateControl extends Control
{

	public function render(): void
	{
		$this->template->setFile(__DIR__ . '/discoveryTwoTemplatePrimary.latte');
		$this->template->render();
	}

	public function renderTable(): void
	{
		$this->template->setFile(__DIR__ . '/discoveryTwoTemplateTable.latte');
		$this->template->render();
	}

}
