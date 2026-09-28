<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Fleet;

/**
 * @property-read FleetTemplate01 $template
 */
final class FleetPhpdoc01Presenter
{

	public function actionDefault(): void
	{
		$this->template->headline1 = 'phpdoc-01';
	}

}
