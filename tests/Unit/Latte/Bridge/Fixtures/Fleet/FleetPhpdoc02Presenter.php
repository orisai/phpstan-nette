<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Fleet;

/**
 * @property-read FleetTemplate02 $template
 */
final class FleetPhpdoc02Presenter
{

	public function actionDefault(): void
	{
		$this->template->headline2 = 'phpdoc-02';
	}

}
