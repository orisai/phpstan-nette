<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Includes;

// Tags that always appear as {name}...{/name} pairs, on every Latte line; a name missing here is treated
// as a single tag and never opens a nesting level, so {var} inside it would wrongly count as top-level.
final class PairedTags
{

	public const NAMES = [
		'if', 'ifset', 'ifcontent', 'ifchanged', 'switch', 'foreach', 'iterateWhile',
		'for', 'while', 'first', 'last', 'sep', 'try', '_', 'translate', 'capture',
		'spaceless', 'tag', 'snippet', 'block', 'define', 'embed', 'snippetArea',
		'ifCurrent', 'form', 'formContext', 'formContainer', 'label', 'name', 'cache',
	];

	private function __construct()
	{
	}

}
