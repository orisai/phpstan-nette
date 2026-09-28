<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App;

use Nette\Application\UI\Presenter;

final class PairingDefaultsSilentPresenter extends Presenter
{

	public function actionDefault(): void
	{
		$this->template->quiet = 'silent';
	}

}
