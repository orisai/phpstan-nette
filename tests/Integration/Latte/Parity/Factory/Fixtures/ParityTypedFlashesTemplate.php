<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Parity\Factory\Fixtures;

use Nette\Bridges\ApplicationLatte\Template;

// $flashes declared with a type the factory's own value ([]) cannot satisfy - the probe for whether
// the installed vendor gates injection on type compatibility as well as on property_exists().
final class ParityTypedFlashesTemplate extends Template
{

	public int $flashes = 0;

}
