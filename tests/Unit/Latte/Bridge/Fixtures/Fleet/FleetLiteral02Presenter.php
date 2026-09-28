<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Fleet;

use Nette\Application\UI\Template;
use stdClass;

final class FleetLiteral02Presenter
{

	/** @var Template|stdClass */
	public $template;

	public function actionDefault(): void
	{
		$this->template->title2 = 'fleet-02';
		$this->template->count2 = 14;
		$this->template->ratio2 = 2.5;
		$this->template->active2 = false;
	}

}
