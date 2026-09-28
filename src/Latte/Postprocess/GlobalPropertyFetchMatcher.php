<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess;

use PhpParser\Node;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;

// The one shape every $this->global->{property} read (uiControl/uiPresenter/snippetDriver/
// formsStack, i.e. any UIRuntime-provided global) is matched against - UiMacroEliminator,
// FormsMacroEliminator, BlockDispatchEliminator and ProviderMacroScanner each used to carry an
// identical private isGlobalPropertyFetch() copy; extracted here so the shape is defined once.
final class GlobalPropertyFetchMatcher
{

	private function __construct()
	{
	}

	public static function matches(Node $node, string $property): bool
	{
		if (
			!$node instanceof PropertyFetch
			|| !$node->name instanceof Identifier
			|| $node->name->toString() !== $property
		) {
			return false;
		}

		$global = $node->var;

		return $global instanceof PropertyFetch
			&& $global->name instanceof Identifier
			&& $global->name->toString() === 'global'
			&& $global->var instanceof Variable
			&& $global->var->name === 'this';
	}

}
