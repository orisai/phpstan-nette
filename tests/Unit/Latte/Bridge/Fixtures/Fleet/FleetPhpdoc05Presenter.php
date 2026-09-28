<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Fleet;

/**
 * @property-read FleetTemplate05 $template
 */
final class FleetPhpdoc05Presenter
{

	public function actionDefault(): void
	{
		$this->template->headline5 = 'phpdoc-05';
	}

}
