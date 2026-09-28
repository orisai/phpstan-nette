<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Fleet;

use Nette\Application\UI\Template;
use stdClass;

final class FleetLiteral03Presenter
{

	/** @var Template|stdClass */
	public $template;

	public function actionDefault(): void
	{
		$this->template->title3 = 'fleet-03';
		$this->template->count3 = 21;
		$this->template->ratio3 = 3.5;
		$this->template->active3 = true;
	}

}
