<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version;

use Latte\Engine;
use OriPhpstan\Nette\Latte\Customs\HarvestedCustoms;

interface LatteEngineReader
{

	public function read(Engine $engine): HarvestedCustoms;

}
