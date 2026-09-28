<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Fleet;

use Nette\Application\UI\Template;
use stdClass;

final class FleetConditional01Presenter
{

	/** @var Template|stdClass */
	public $template;

	/** @var bool */
	public $enabled = false;

	public function actionDefault(): void
	{
		$this->template->always1 = 'base-01';

		if ($this->enabled) {
			$this->template->sometimes1 = 'branch-01';
		}
	}

}
