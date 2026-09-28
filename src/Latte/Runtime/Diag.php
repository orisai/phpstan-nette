<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Runtime;

final class Diag
{

	public static function report(string $identifier, string $message, ?string $tip = null): void
	{
		// Analysis-only marker reported by a rule. Never executed.
	}

}
