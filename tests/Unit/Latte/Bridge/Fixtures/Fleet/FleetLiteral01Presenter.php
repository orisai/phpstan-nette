<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Fleet;

use Nette\Application\UI\Template;
use stdClass;

final class FleetLiteral01Presenter
{

	/** @var Template|stdClass */
	public $template;

	public function actionDefault(): void
	{
		$this->template->title1 = 'fleet-01';
		$this->template->count1 = 7;
		$this->template->ratio1 = 1.5;
		$this->template->active1 = true;
	}

}
