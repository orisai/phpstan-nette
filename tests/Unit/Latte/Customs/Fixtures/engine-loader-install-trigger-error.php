<?php declare(strict_types = 1);

use Latte\Engine;
use Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures\FixtureInstallTriggerErrorMacroSet;

require_once __DIR__ . '/../../../../../tests/autoload.php';

$engine = new Engine();
$engine->onCompile[] = static function (Engine $engine): void {
	FixtureInstallTriggerErrorMacroSet::install($engine->getCompiler());
};

return $engine;
