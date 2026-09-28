<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Template;
use stdClass;

final class UnresolvableRhsPresenter
{

	/** @var Template|stdClass */
	public $template;

	public function actionDefault(): void
	{
		$method = 'find';
		$this->template->x = $this->$method();
	}

	public function find(): string
	{
		return 'whatever';
	}

}
