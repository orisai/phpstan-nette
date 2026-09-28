<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures;

use Latte\Engine;
use Nette\Bridges\ApplicationLatte\LatteFactory;

final class FixtureLatteFactoryMacroIdentityBase implements LatteFactory
{

	public function create(): Engine
	{
		$engine = new Engine();

		$engine->addFilter('fixtureFilter', [FixtureMacroIdentityCallables::class, 'filter']);
		$engine->addFunction('fixtureFunction', [FixtureMacroIdentityCallables::class, 'fn']);

		$engine->onCompile[] = static function (Engine $engine): void {
			FixtureMacroSet::install($engine->getCompiler());
		};

		return $engine;
	}

}
