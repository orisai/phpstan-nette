<?php declare(strict_types = 1);

use Latte\Engine;

require_once __DIR__ . '/../../../../../tests/autoload.php';

$engine = new Engine();
$engine->addFilter('wrapped', static function (string $s): string {
	return '[' . $s . ']';
});
$engine->addFunction('twice', static function (int $n): int {
	return 2 * $n;
});

return $engine;
