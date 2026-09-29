<?php declare(strict_types = 1);

use Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures\FixtureGettextMacros;
use Latte\Engine;
use Nette\Bridges\ApplicationLatte\UIMacros;
use Nette\Bridges\CacheLatte\CacheMacro;
use Nette\Bridges\FormsLatte\FormMacros;

require_once __DIR__ . '/../../../../../tests/autoload.php';

// Mirrors production's real registration shape (Nette\Bridges\ApplicationLatte\TemplateFactory +
// h4kuna\Gettext\DI\GettextLatteExtension): CoreMacros/BlockMacros install eagerly the moment
// $engine->getCompiler() is first touched (Latte\Engine::getCompiler()), then this onCompile
// handler adds UIMacros/FormMacros/the vendor (nondeterministic) CacheMacro/Gettext - the exact
// shape the Latte 2 engine reader sees when harvesting the real app container, where
// getMacroSets() legitimately includes Latte's own built-ins alongside the app's customs.
$engine = new Engine();
$engine->onCompile[] = static function (Engine $engine): void {
	$compiler = $engine->getCompiler();
	UIMacros::install($compiler);
	FormMacros::install($compiler);
	$compiler->addMacro('cache', new CacheMacro());
	FixtureGettextMacros::install($compiler);
};

return $engine;
