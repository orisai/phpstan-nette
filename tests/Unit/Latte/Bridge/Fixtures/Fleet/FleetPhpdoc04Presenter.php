<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Fleet;

/**
 * @property-read FleetTemplate04 $template
 */
final class FleetPhpdoc04Presenter
{

	public function actionDefault(): void
	{
		$this->template->headline4 = 'phpdoc-04';
	}

}
