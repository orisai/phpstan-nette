<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Component\Attachment;

use OriPhpstan\Nette\Forms\Shape\Certainty;
use function strpos;
use function ucfirst;

/**
 * What `Container::getComponent($name)` ATTACHES, which is not the same question as what it returns.
 *
 * The read is lazy: a name the container does not hold yet is handed to `createComponent()`, and a
 * component that comes back is added under that name. So the one call in the whole component model
 * that both reads and writes writes only under conditions the vendor body states outright, and both
 * of them are answerable here (re-verified against nette/component-model as installed):
 *
 *     if (!isset($this->components[$name])) {        // already attached: nothing below runs
 *         ...
 *         $component = $this->createComponent($name);
 *         if ($component && !isset($this->components[$name])) {
 *             $this->addComponent($component, $name);
 *         }
 *     }
 *
 * and `createComponent()` returns null - never a component - unless `createComponent<Ucname>` is
 * declared on the receiver. Either half alone therefore PROVES the call attaches nothing: a child the
 * container definitely holds skips the whole block, and a receiver with no factory for the name
 * reaches `$component = null` and adds nothing (it then throws, which is the absence rule's business,
 * not this one's). The call attaches definitely only where both point the other way: the child is
 * definitely absent AND a factory exists, in which case the call either returns an attached component
 * or throws - `createComponent()` refuses to let a factory neither return nor attach one.
 *
 * The approximation this retires read the CALLEE'S BODY and asked whether every path through it
 * reached a registration. That bar existed for this one method: `getComponent()` registers inside an
 * `if`, so at the looser "registers at all" bar it qualified, and following it opened the shape of
 * every form somebody read a component off - deleting the absence reports the read itself is judged
 * by. The bar was never about conditionality; it was a way of spelling "except getComponent". With
 * the two facts above the exception is derived per CALL instead of guessed per body.
 *
 * `offsetGet()` and `offsetExists()` reach the same lazy path (`offsetGet` delegates outright,
 * `offsetExists` calls the no-throw form), but neither registers anything in its OWN body, so no
 * body-reading consumer ever asks about them and this class does not claim to speak for them.
 *
 * The child asked about is the one the RECEIVER can gain, which for a '-'-joined name is its first
 * segment: Nette explodes the name and applies the block above to that segment alone, then delegates
 * the rest to whatever child it found. Splitting is component-path semantics and belongs to
 * ComponentPath, so the caller does it and hands the segment over; a joined name arriving here means
 * that contract was not honoured, and it degrades rather than guessing which half it describes.
 * Whether the delegated hop attaches anything INSIDE that child is a question about the child's own
 * container, not about this receiver, and nothing here claims to answer it.
 */
final class ContainerLazyRead
{

	/**
	 * Nette's factory convention. `createComponent()` requires `ucfirst($name) !== $name`, so a child
	 * whose name is already capitalised can have no factory at all - which makes every read of it a
	 * proven non-attachment rather than an unanswered one.
	 */
	public const FACTORY_PREFIX = 'createComponent';

	private const NAME_SEPARATOR = '-';

	private function __construct()
	{
	}

	public static function isLazyRead(string $method): bool
	{
		return $method === AttachmentTransitions::GET_COMPONENT;
	}

	public static function factoryMethodFor(string $childName): ?string
	{
		$ucname = ucfirst($childName);

		return $ucname === $childName ? null : self::FACTORY_PREFIX . $ucname;
	}

	/**
	 * The Certainty that a `getComponent(…)` call attaches a child named $childName to its receiver,
	 * given what the caller knows about that child's presence and about the receiver's factories.
	 * UNKNOWN for a name the caller could not read, which is the default every unproven edge degrades
	 * to.
	 */
	public static function attachesChild(
		?string $childName,
		string $childPresence,
		bool $receiverDeclaresFactory
	): string
	{
		if ($childName === null || strpos($childName, self::NAME_SEPARATOR) !== false) {
			return Certainty::UNKNOWN;
		}

		if ($childPresence === Certainty::HAPPENS || !$receiverDeclaresFactory) {
			return Certainty::NEVER;
		}

		return $childPresence === Certainty::NEVER ? Certainty::HAPPENS : Certainty::MAYBE;
	}

	/**
	 * The Certainty that the receiver HOLDS $childName once the read has run, which is what
	 * `isset($container[$childName])` answers - `offsetExists()` is
	 * `getComponent($name, false) !== null`, so the question is about the tree AFTER the lazy block,
	 * not before it.
	 *
	 * That makes it a different table from attachesChild() above and very nearly its inverse, which
	 * is the way round it is easy to get wrong: a factory makes the answer TRUE, because the read
	 * runs it and adds what it produces (createComponent() refuses to let a factory neither return
	 * nor attach a component - it throws instead, so there is no path on which a declared factory
	 * leaves the name unheld). Reasoning "not attached, therefore isset is false" inverts it.
	 *
	 *     child presence   factory      isset
	 *     HAPPENS          either       TRUE     already held; the lazy block is skipped
	 *     any              yes          TRUE     held afterwards whether or not it was before
	 *     NEVER            no           FALSE    nothing to find and nothing to create
	 *     MAYBE/UNKNOWN    no           Maybe
	 *
	 * A '-'-joined name is refused here exactly as attachesChild() refuses one: Nette resolves it hop
	 * by hop, and the presence handed over describes one child. Whether a name is a legal component
	 * name at all is the CALLER's to establish before asking - that predicate is component-path
	 * semantics and lives in ComponentPath, which this namespace does not read.
	 */
	public static function holdsChildAfterRead(
		?string $childName,
		string $childPresence,
		bool $receiverDeclaresFactory
	): string
	{
		if ($childName === null || strpos($childName, self::NAME_SEPARATOR) !== false) {
			return Certainty::UNKNOWN;
		}

		if ($childPresence === Certainty::HAPPENS || $receiverDeclaresFactory) {
			return Certainty::HAPPENS;
		}

		return $childPresence === Certainty::NEVER ? Certainty::NEVER : Certainty::MAYBE;
	}

}
