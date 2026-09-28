<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Fleet;

use Nette\Application\UI\Template;
use stdClass;

final class FleetConditional03Presenter
{

	/** @var Template|stdClass */
	public $template;

	/** @var bool */
	public $enabled = false;

	public function actionDefault(): void
	{
		$this->template->always3 = 'base-03';

		if ($this->enabled) {
			$this->template->sometimes3 = 'branch-03';
		}
	}

}
