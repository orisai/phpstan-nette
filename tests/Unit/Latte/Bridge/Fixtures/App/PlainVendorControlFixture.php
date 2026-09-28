<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Control;

// The StatusBar shape: a plain vendor-Control descendant with no app base class, no *Presenter
// name and no factory call - the vendor reflection surface alone must qualify it.
final class PlainVendorControlFixture extends Control
{

	public function render(): void
	{
		$this->template->statusText = 'ok';
		$this->template->render(__DIR__ . '/plainVendorControl.latte');
	}

}
