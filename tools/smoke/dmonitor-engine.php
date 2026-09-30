<?php declare(strict_types = 1);

use App\Bootstrap;
use Latte\Essential\RawPhpExtension;
use Nette\Bridges\ApplicationLatte\LatteFactory;

// The engine dmonitor's DI builds (its four extensions, the bridges, strict types), plus what its
// presenters and DataGrid add per template: isAllowed(), getSnippetId() and {php}.
$factory = Bootstrap::bootForTests()->getService('latte.latteFactory');
assert($factory instanceof LatteFactory);

$engine = $factory->create();
$engine->addFunction('isAllowed', static fn (string $privilege): bool => true);
$engine->addFunction('getSnippetId', static fn (string $name): string => $name);
$engine->addExtension(new RawPhpExtension());

return $engine;
