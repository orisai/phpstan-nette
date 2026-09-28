<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Template;
use stdClass;

final class LiteralAssignmentPresenter
{

	/** @var Template|stdClass */
	public $template;

	public function actionDefault(): void
	{
		$this->template->str = 'hello';
		$this->template->num = 42;
		$this->template->flt = 3.14;
		$this->template->flag = true;
		$this->template->nil = null;
		$this->template->arr = [1, 2, 3];
		$this->template->obj = new LiteralAssignmentTarget();
	}

}
