<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Template;
use stdClass;

// Qualifies via its @property surface, but its own getTemplate() does not return a template -
// $this->getTemplate() must not be provenance-tracked by name alone.
final class NonTemplateIshOwnGetTemplatePresenter
{

	/** @var Template|stdClass */
	public $template;

	public function actionDefault(): void
	{
		$tpl = $this->getTemplate();
		$tpl->boom = 'x';
	}

	private function getTemplate(): LiteralAssignmentTarget
	{
		return new LiteralAssignmentTarget();
	}

}
