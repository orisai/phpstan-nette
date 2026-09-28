<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Presenter;

final class DiscoverySetFileConditionalPresenter extends Presenter
{

	/** @var bool */
	public $custom = false;

	public function actionFoo(): void
	{
		if ($this->custom) {
			$this->getTemplate()->setFile(__DIR__ . '/custom-conditional.latte');
		}
	}

}
