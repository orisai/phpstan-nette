<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Bridge;

// The one shared qualification predicate over walked facts: a resolved template class IS the
// qualification signal - the walk leaves it null for unknown and non-qualifying classes alike.
final class Qualification
{

	private function __construct()
	{
	}

	/**
	 * @phpstan-assert-if-true !null $facts->getTemplateClass()
	 */
	public static function qualifies(PhpRenderFacts $facts): bool
	{
		return $facts->getTemplateClass() !== null;
	}

}
