<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Presenter;

// Deliberately not *Presenter-named: the fixture mapping mask reverse-maps App\*Presenter only,
// so this class reproduces the real corpus's non-reverse-mapping presenters.
final class DiscoveryUnmappedEndpoint extends Presenter
{

	public function actionDefault(): void
	{
	}

}
