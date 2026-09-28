<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

// Overrides the inherited property default to null while inheriting the locator, so its name is
// derived. Its directory basename (App) differs from its short class name, which is what makes the
// derived name discriminate against the dirname-lcfirst formula.
final class DiscoveryPropertyNameNullControl extends FixturePropertyNameControlBase
{

	/** @var string|null */
	protected $layout = null;

	public function render(): void
	{
		$this->createTemplate()->render();
	}

}
