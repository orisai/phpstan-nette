<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Template;
use stdClass;

final class HelperConditionalityPresenter
{

	/** @var Template|stdClass */
	public $template;

	/** @var bool */
	public $flag = false;

	public function actionDefault(): void
	{
		if ($this->flag) {
			$this->setupUnconditionalHelper();
		}

		$this->setupConditionalHelper();
	}

	private function setupUnconditionalHelper(): void
	{
		$this->template->fromUnconditionalHelper = 'value';
	}

	private function setupConditionalHelper(): void
	{
		if ($this->flag) {
			$this->template->fromConditionalHelper = 'value';
		}
	}

}
