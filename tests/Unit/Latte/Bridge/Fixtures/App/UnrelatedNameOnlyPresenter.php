<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

// Named like a presenter but with no template surface at all - the name alone must not qualify.
final class UnrelatedNameOnlyPresenter
{

	/** @var mixed */
	public $template;

	public function actionDefault(): void
	{
		$this->template->leaked = 'x';
	}

}
