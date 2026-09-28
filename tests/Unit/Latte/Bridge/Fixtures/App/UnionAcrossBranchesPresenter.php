<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Template;
use stdClass;

final class UnionAcrossBranchesPresenter
{

	/** @var Template|stdClass */
	public $template;

	/** @var bool */
	public $flag = false;

	public function actionDefault(): void
	{
		if ($this->flag) {
			$this->template->x = 'foo';
			$this->template->branch = 'if';
		} else {
			$this->template->x = 5;
			$this->template->branch = 'else';
		}
	}

}
