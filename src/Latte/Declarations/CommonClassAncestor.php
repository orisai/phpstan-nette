<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Declarations;

use function class_exists;
use function get_parent_class;
use function is_a;
use function ltrim;

// The widening two disagreeing renderer types fold to. The true type of a variable in a template
// rendered by R1..Rn is the UNION of their types, so any common supertype over-approximates it and
// can only miss findings, never invent them; the deepest common class is the tightest such answer.
//
// A union would be tighter still, and is rejected on message size - a layout shared by dozens of
// presenters would carry a dozens-wide union in its injected @var and in every error it produces.
//
// Classes only: class_exists() answers false for an interface, so two types sharing only an
// interface widen to mixed rather than to that interface.
final class CommonClassAncestor
{

	public const NO_COMMON_ANCESTOR = 'mixed';

	private function __construct()
	{
	}

	public static function of(string $a, string $b): string
	{
		if ($a === $b) {
			return $a;
		}

		$left = ltrim($a, '\\');
		$right = ltrim($b, '\\');

		// class_exists() is the same gate FactoryProvidedVars::readTemplateClass() uses, and it is
		// load-bearing here for a second reason: PropertyTypeResolver hands back the RAW @var string,
		// so an imported short name never resolves and falls through to the no-answer below.
		if (!class_exists($left) || !class_exists($right)) {
			return self::NO_COMMON_ANCESTOR;
		}

		// Emitted with a leading backslash because compiled templates carry NO namespace - a bare
		// name in an injected @var resolves to the global one.
		foreach (self::chain($left) as $candidate) {
			if (is_a($right, $candidate, true)) {
				return '\\' . $candidate;
			}
		}

		return self::NO_COMMON_ANCESTOR;
	}

	/**
	 * @return list<string>
	 */
	private static function chain(string $class): array
	{
		$chain = [$class];

		$parent = $class;
		while (($parent = get_parent_class($parent)) !== false) {
			$chain[] = $parent;
		}

		return $chain;
	}

}
