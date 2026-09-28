<?php declare(strict_types = 1);

use Latte\Engine;
use Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures\FixtureMacroSet;

require_once __DIR__ . '/../../../../../vendor/autoload.php';

$engine = new Engine();
$engine->addFilter('fixtureFilter', static fn (string $s): string => $s);
$engine->addFunction('fixtureFunction', static fn (int $n): int => $n);

$engine->onCompile[] = static function (Engine $engine): void {
	// Simulates a real extension's onCompile hook emitting its own diagnostics during
	// registration - CustomsHarvester's containment must swallow these exactly like vendor
	// Latte's, never leak them, never let them break harvest.
	trigger_error('fixture harvest deprecated notice', E_USER_DEPRECATED);
	trigger_error('fixture harvest warning notice', E_USER_WARNING);
	FixtureMacroSet::install($engine->getCompiler());
};

return $engine;
