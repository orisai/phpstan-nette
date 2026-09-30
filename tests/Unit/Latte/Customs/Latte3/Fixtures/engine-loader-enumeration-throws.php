<?php declare(strict_types = 1);

use Latte\Engine;
use Tests\OriPhpstan\Nette\Unit\Latte\Customs\Latte3\Fixtures\ThrowingTagsExtension;

require_once __DIR__ . '/../../../../../../tests/autoload.php';

$engine = new Engine();
$engine->addExtension(new ThrowingTagsExtension());

return $engine;
