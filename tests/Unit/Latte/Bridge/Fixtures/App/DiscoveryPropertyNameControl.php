<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

// Read by no fixture code, the two property shapes the formula cannot turn into a name:
// $layoutNames is the non-string default, $layoutFile the typed declaration with no default at all
// - whose runtime read throws, and which BetterReflection's getDefaultProperties() still maps to
// null, so only the property's own hasDefaultValue() tells it apart from an explicit null.
final class DiscoveryPropertyNameControl extends FixturePropertyNameControlBase
{

	/** @var list<string> */
	protected $layoutNames = ['default'];

	protected ?string $layoutFile;

	public function render(): void
	{
		$this->createTemplate()->render();
	}

}
