<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures;

use Latte\Compiler;
use Latte\Macros\MacroSet;
use function trigger_error;
use const E_USER_WARNING;

final class FixtureInstallTriggerErrorMacroSet extends MacroSet
{

	// Simulates a real harvested macro set that fires trigger_error() from inside its OWN
	// install() - registration-time, not compile-time - the exact site
	// LatteCompiler::installHarvestedMacroSets()'s VendorErrorContainment wrap must cover.
	public static function install(Compiler $compiler): self
	{
		trigger_error('fixture install-time notice', E_USER_WARNING);

		$me = new self($compiler);
		$me->addMacro(
			'fixtureInstallTriggerErrorMacro',
			'/* fixtureInstallTriggerErrorMacro */',
			'/* /fixtureInstallTriggerErrorMacro */',
		);

		return $me;
	}

}
