<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App;

use Nette\Application\UI\Presenter;

// Qualifies through the inherited template surface but names no template class of its own, so the
// resolution ladder answers with its fallback rungs alone.
final class FactoryVarsFloorPresenter extends Presenter
{

	public function actionDefault(): void
	{
		$this->template->quiet = 'silent';
	}

}
