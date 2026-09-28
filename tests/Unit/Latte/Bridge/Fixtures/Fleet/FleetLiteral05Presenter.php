<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Fleet;

use Nette\Application\UI\Template;
use stdClass;

final class FleetLiteral05Presenter
{

	/** @var Template|stdClass */
	public $template;

	public function actionDefault(): void
	{
		$this->template->title5 = 'fleet-05';
		$this->template->count5 = 35;
		$this->template->ratio5 = 5.5;
		$this->template->active5 = true;
	}

}
