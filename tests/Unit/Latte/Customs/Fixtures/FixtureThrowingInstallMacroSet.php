<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures;

use Latte\Compiler;
use Latte\Macros\MacroSet;
use RuntimeException;

final class FixtureThrowingInstallMacroSet extends MacroSet
{

	// Always throws - forces LatteCompiler::installHarvestedMacroSets()'s
	// try/catch (Throwable) around $class::install($compiler) to skip this harvested class.
	// The fixture engine-loader registers a harvested INSTANCE without ever calling this method
	// (bypassing it via a direct addMacro() call, the same way production's own CacheMacro is
	// registered without a static factory), so harvest itself still succeeds - only the FRESH
	// reinstall LatteCompiler attempts per compile hits this throw.
	public static function install(Compiler $compiler): self
	{
		throw new RuntimeException('fixture install() always throws');
	}

}
