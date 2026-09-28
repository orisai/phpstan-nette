<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures;

use Latte\Engine;
use Nette\Bridges\ApplicationLatte\LatteFactory;

final class FixtureLatteFactory implements LatteFactory
{

	public function create(): Engine
	{
		$engine = new Engine();

		$engine->addFilter('fixtureFilter', static fn (string $s): string => $s);

		$engine->addFunction('fixtureFunction', [self::class, 'fixtureFunctionImpl']);

		$engine->onCompile[] = static function (Engine $engine): void {
			FixtureMacroSet::install($engine->getCompiler());
		};

		return $engine;
	}

	public static function fixtureFunctionImpl(int $n): int
	{
		return $n;
	}

}
