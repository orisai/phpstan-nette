<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Template;
use stdClass;

// Qualifies via its @property surface, but its own createTemplate() does not return a template -
// $this->createTemplate() must not be provenance-tracked by name alone.
final class NonTemplateIshOwnCreateTemplatePresenter
{

	/** @var Template|stdClass */
	public $template;

	public function actionDefault(): void
	{
		$tpl = $this->createTemplate();
		$tpl->boom = 'x';
	}

	private function createTemplate(): LiteralAssignmentTarget
	{
		return new LiteralAssignmentTarget();
	}

}
