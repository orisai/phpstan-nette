<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte;

use PHPStan\Analyser\Scope;

// Public API: a rule with fixNode() must consult supportsFixes() so .latte-derived nodes are never rewritten.
final class FixSupport
{

	private function __construct()
	{
	}

	// Latte-materialized files have no real token stream: RuleErrorBuilder::fixNode() renders
	// its preview via printFormatPreserving(), which walks TokenStream positions that only exist
	// for genuinely lexed .php source and crashes (Internal error) against our compiled output.
	public static function supportsFixes(Scope $scope): bool
	{
		return !TemplatePath::is($scope->getFile());
	}

}
