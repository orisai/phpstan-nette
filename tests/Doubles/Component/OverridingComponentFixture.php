<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Component;

use Nette\Application\UI\Control;
use Nette\Application\UI\Presenter;

/**
 * A project override of a parent accessor. It resolves to its own declaring class rather than to
 * Nette's, which is the whole reason ParentAccessors is keyed by declaring class: an override this
 * cannot read may not throw at all.
 */
final class OverridingComponentFixture extends Control
{

	public function getPresenter(): ?Presenter
	{
		return $this->getPresenterIfExists();
	}

}
