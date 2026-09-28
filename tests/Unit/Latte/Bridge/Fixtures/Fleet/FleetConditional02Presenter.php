<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Fleet;

use Nette\Application\UI\Template;
use stdClass;

final class FleetConditional02Presenter
{

	/** @var Template|stdClass */
	public $template;

	/** @var bool */
	public $enabled = false;

	public function actionDefault(): void
	{
		$this->template->always2 = 'base-02';

		if ($this->enabled) {
			$this->template->sometimes2 = 'branch-02';
		}
	}

}
