<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Presenter;

// A vendor-Presenter descendant with no template-related override and no *Presenter name -
// qualification and the fallback rungs of the template-class ladder both come from the vendor
// surface alone.
final class VendorSurfaceDescendantFixture extends Presenter
{

	public function actionDefault(): void
	{
		$this->template->fromVendorSurface = 'x';
	}

}
