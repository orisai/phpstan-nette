<?php declare(strict_types = 1);

use Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures\FixtureGettextMacros;
use Latte\Engine;

require_once __DIR__ . '/../../../../../vendor/autoload.php';

// The gettext macro set (FixtureGettextMacros, a replica of h4kuna\Gettext\Macros\Gettext): its
// install() takes only a Latte\Compiler, same as CoreMacros/BlockMacros/UIMacros/FormMacros - no
// DI-constructed GettextSetup/Dictionary/Os dependency is needed to COMPILE {_}/{g_}/{ng_}/{dg_}/
// {dng_} tags, only to render them at runtime.
$engine = new Engine();
$engine->onCompile[] = static function (Engine $engine): void {
	FixtureGettextMacros::install($engine->getCompiler());
};

return $engine;
