<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit\Latte3;

use OriPhpstan\Nette\Latte\Version\ShapeFamily;

// The Latte 3 shape families for tests that read committed Latte 3 output on any installed Latte.
final class Latte3Layouts
{

	public static function family(string $latteLine): ShapeFamily
	{
		return new ShapeFamily(
			$latteLine,
			$latteLine === ShapeFamily::LATTE_30 ? ShapeFamily::FORMS_ITEM : ShapeFamily::FORMS_PROVIDER,
		);
	}

}
