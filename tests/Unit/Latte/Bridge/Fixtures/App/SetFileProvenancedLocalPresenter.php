<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Template;
use stdClass;

final class SetFileProvenancedLocalPresenter
{

	/** @var Template|stdClass */
	public $template;

	public function actionDefault(): void
	{
		$tpl = $this->template;
		$tpl->setFile(__DIR__ . '/via-local.latte');
	}

}
