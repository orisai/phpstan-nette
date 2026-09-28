<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Fleet;

use Nette\Application\UI\Template;
use stdClass;

final class FleetConditional05Presenter
{

	/** @var Template|stdClass */
	public $template;

	/** @var bool */
	public $enabled = false;

	public function actionDefault(): void
	{
		$this->template->always5 = 'base-05';

		if ($this->enabled) {
			$this->template->sometimes5 = 'branch-05';
		}
	}

}
