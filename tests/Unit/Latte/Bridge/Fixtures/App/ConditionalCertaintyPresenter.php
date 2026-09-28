<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Template;
use stdClass;

final class ConditionalCertaintyPresenter
{

	/** @var Template|stdClass */
	public $template;

	/** @var bool */
	public $flag = false;

	public function actionDefault(): void
	{
		$this->template->definite = 'x';

		if ($this->flag) {
			$this->template->maybe = 'y';
		}
	}

}
