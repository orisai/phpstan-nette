<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Template;
use stdClass;

final class ConditionalAfterUnconditionalPresenter
{

	/** @var Template|stdClass */
	public $template;

	/** @var bool */
	public $flag = false;

	public function actionDefault(): void
	{
		$this->template->x = 'foo';

		if ($this->flag) {
			$this->template->x = 5;
		}
	}

}
