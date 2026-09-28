<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Presenter;

// One render method, five template files in its formula directory: the view set is the UNION of
// both channels, and the file channel is held to the vendor's action-name grammar.
final class DiscoveryFileViewPresenter extends Presenter
{

	public function renderDefault(): void
	{
	}

}
