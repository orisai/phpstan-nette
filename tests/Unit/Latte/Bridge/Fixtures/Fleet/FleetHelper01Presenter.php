<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Fleet;

use Nette\Application\UI\Template;
use stdClass;

final class FleetHelper01Presenter
{

	/** @var Template|stdClass */
	public $template;

	public function actionDefault(): void
	{
		$this->applyDefaults();
	}

	private function applyDefaults(): void
	{
		$this->template->fromHelper1 = 'helper-01';
		$this->applyExtras();
	}

	private function applyExtras(): void
	{
		$this->template->fromExtras1 = 11;
	}

}
