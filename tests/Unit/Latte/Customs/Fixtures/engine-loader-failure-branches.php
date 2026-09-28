<?php declare(strict_types = 1);

use Latte\Engine;
use Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures\FixtureNoInstallMacro;
use Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures\FixtureThrowingInstallMacroSet;

require_once __DIR__ . '/../../../../../vendor/autoload.php';

$engine = new Engine();
$engine->onCompile[] = static function (Engine $engine): void {
	$compiler = $engine->getCompiler();

	// Registered via a bare addMacro() call (production's own CacheMacro pattern), never through a
	// static install() - harvest captures an instance of each class without either fixture's own
	// broken/absent factory ever running, so LatteCompiler::installHarvestedMacroSets() is the
	// FIRST place either failure path is actually exercised.
	$compiler->addMacro('fixtureNoInstallMacro', new FixtureNoInstallMacro());

	$throwing = new FixtureThrowingInstallMacroSet($compiler);
	$throwing->addMacro(
		'fixtureThrowingInstallMacro',
		'/* fixtureThrowingInstallMacro */',
		'/* /fixtureThrowingInstallMacro */',
	);
};

return $engine;
