<?php declare(strict_types = 1);

use Latte\Engine;
use Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures\InvocationCounter;

require_once __DIR__ . '/../../../../../tests/autoload.php';

InvocationCounter::$count++;

return new Engine();
