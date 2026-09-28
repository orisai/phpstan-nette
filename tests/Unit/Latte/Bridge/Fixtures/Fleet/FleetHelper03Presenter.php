<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Fleet;

use Nette\Application\UI\Template;
use stdClass;

final class FleetHelper03Presenter
{

	/** @var Template|stdClass */
	public $template;

	public function actionDefault(): void
	{
		$this->applyDefaults();
	}

	private function applyDefaults(): void
	{
		$this->template->fromHelper3 = 'helper-03';
		$this->applyExtras();
	}

	private function applyExtras(): void
	{
		$this->template->fromExtras3 = 13;
	}

}
