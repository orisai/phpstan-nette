<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Template;
use stdClass;

final class LoopBodyCertaintyPresenter
{

	/** @var Template|stdClass */
	public $template;

	/** @var list<int> */
	public $items = [];

	/** @var bool */
	public $flag = false;

	public function actionDefault(): void
	{
		foreach ($this->items as $item) {
			$this->template->fromForeach = 'x';
		}

		while ($this->flag) {
			$this->template->fromWhile = 'y';
			$this->flag = false;
		}
	}

}
