<?php declare(strict_types = 1);

use Latte\Engine;

require_once __DIR__ . '/../../../../../../tests/autoload.php';

$engine = new Engine();
$engine->addFilter('shouted', strtoupper(...));
$engine->addFunction('lengthOf', strlen(...));
$engine->addFunction('repeated', str_repeat(...));

return $engine;
