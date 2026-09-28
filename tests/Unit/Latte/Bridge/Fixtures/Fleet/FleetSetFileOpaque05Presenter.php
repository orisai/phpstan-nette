<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Fleet;

use Nette\Application\UI\Template;
use stdClass;

final class FleetSetFileOpaque05Presenter
{

	/** @var Template|stdClass */
	public $template;

	public function actionDefault(): void
	{
		$dynamic = $this->buildDynamicPath();
		$this->template->setFile($dynamic);
	}

	private function buildDynamicPath(): string
	{
		return 'fleet-dynamic-05.latte';
	}

}
