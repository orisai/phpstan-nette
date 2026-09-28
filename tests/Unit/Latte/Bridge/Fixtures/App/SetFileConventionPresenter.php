<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Template;
use stdClass;

final class SetFileConventionPresenter
{

	/** @var Template|stdClass */
	public $template;

	public function actionDefault(): void
	{
		$this->template->setFile($this->getTemplateFilePath());
	}

	private function getTemplateFilePath(): string
	{
		return __DIR__ . '/convention.latte';
	}

}
