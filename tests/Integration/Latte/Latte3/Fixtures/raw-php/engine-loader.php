<?php declare(strict_types = 1);

use Latte\Engine;
use Latte\Essential\RawPhpExtension;

require_once __DIR__ . '/../../../../../autoload.php';

$engine = new Engine();
$engine->addExtension(new RawPhpExtension());

return $engine;
