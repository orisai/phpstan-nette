<?php declare(strict_types = 1);

use Latte\Engine;
use Latte\Feature;

require_once __DIR__ . '/../../../../../../tests/autoload.php';

$engine = new Engine();
$engine->setFeature(Feature::StrictTypes, true);

return $engine;
