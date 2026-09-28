<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Fleet;

use Nette\Application\UI\Template;
use stdClass;

final class FleetLiteral04Presenter
{

	/** @var Template|stdClass */
	public $template;

	public function actionDefault(): void
	{
		$this->template->title4 = 'fleet-04';
		$this->template->count4 = 28;
		$this->template->ratio4 = 4.5;
		$this->template->active4 = false;
	}

}
