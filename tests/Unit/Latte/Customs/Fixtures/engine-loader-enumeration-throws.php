<?php declare(strict_types = 1);

use Latte\Engine;

require_once __DIR__ . '/../../../../../tests/autoload.php';

$engine = new Engine();
$engine->onCompile[] = static function (Engine $engine): void {
	throw new RuntimeException('fixture enumeration-stage failure');
};

return $engine;
