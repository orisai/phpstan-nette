<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures;

use Latte;

// Deliberately has no static install(Compiler): self factory - the same shape as the real vendor
// Nette\Bridges\CacheLatte\CacheMacro (implements Latte\Macro directly, registered via a bare
// `new` + addMacro() call, no factory convention). Forces
// LatteCompiler::installHarvestedMacroSets()'s `is_callable([$class, 'install'])` guard to return
// false and skip this harvested class.
final class FixtureNoInstallMacro implements Latte\Macro
{

	public function initialize()
	{
	}

	/**
	 * @return array{string, string}|null
	 */
	public function finalize()
	{
		return null;
	}

	/**
	 * @return bool|null
	 */
	public function nodeOpened(Latte\MacroNode $node)
	{
		return true;
	}

	public function nodeClosed(Latte\MacroNode $node)
	{
	}

}
