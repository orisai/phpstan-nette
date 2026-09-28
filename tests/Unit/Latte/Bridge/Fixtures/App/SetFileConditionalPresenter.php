<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Template;
use stdClass;

final class SetFileConditionalPresenter
{

	/** @var Template|stdClass */
	public $template;

	/** @var bool */
	public $flag = false;

	public function actionDefault(): void
	{
		if ($this->flag) {
			$this->template->setFile(__DIR__ . '/cond.latte');
		}
	}

}
