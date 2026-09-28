<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\LatteForms;

// WHAT a named component is, as opposed to WHETHER it exists - the second question the join can
// answer once a name resolves, and the whole input to the type-aware macro checks.
//
// The kind is STRUCTURAL, not nominal: it is the channel the Forms shape recorded the component in
// (a container the walk descended into, a replicator, a value-bearing slot, an omitted-value
// component), which is decided by the builder call that attached it rather than by any declared
// type. A replicator is a container here because that is what Nette makes it at runtime - the
// macros cannot tell the two apart either.
//
// The classes are whatever class evidence that channel carries, and null when it carries none.
// Null is not "no classes": it is the analyser declining to say, and every check that needs a class
// declines with it.
final class ComponentIdentity
{

	public const KIND_CONTAINER = 'container';

	public const KIND_CONTROL = 'control';

	/** @var self::KIND_* */
	private string $kind;

	/** @var list<string>|null */
	private ?array $classes;

	/**
	 * @param self::KIND_* $kind
	 * @param list<string>|null $classes
	 */
	public function __construct(string $kind, ?array $classes)
	{
		$this->kind = $kind;
		$this->classes = $classes;
	}

	/**
	 * @return self::KIND_*
	 */
	public function getKind(): string
	{
		return $this->kind;
	}

	/**
	 * @return list<string>|null
	 */
	public function getClasses(): ?array
	{
		return $this->classes;
	}

}
