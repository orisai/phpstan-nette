<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Compile;

use Latte;

final class PassthroughMacro implements Latte\Macro
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
