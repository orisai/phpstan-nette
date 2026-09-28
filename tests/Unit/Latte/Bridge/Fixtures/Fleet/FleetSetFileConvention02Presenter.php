<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Fleet;

use Nette\Application\UI\Template;
use stdClass;

final class FleetSetFileConvention02Presenter
{

	/** @var Template|stdClass */
	public $template;

	public function actionDefault(): void
	{
		$this->template->setFile($this->templateFilePath());
	}

	private function templateFilePath(): string
	{
		return __DIR__ . '/fleet-convention-02.latte';
	}

}
