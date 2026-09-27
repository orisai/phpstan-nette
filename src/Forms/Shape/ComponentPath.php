<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Shape;

use Nette\ComponentModel\IComponent;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Type;
use function array_key_exists;
use function array_slice;
use function count;
use function explode;
use function preg_match;

/**
 * THE one place that knows what a Nette component-name path means. Every consumer that resolves
 * `$form['bar-baz']`, `$form['bar']['baz']`, `getComponent('bar-baz')` or
 * `getComponent('bar')->getComponent('baz')` goes through here, because all four are the SAME
 * runtime lookup and disagreeing about that is how this extension grew four separate walks.
 *
 * THE VENDOR (vendor/nette/component-model/src/ComponentModel/Container.php:116):
 *
 *     [$name] = $parts = explode(self::NameSeparator, $name, 2);
 *     if (!isset($this->components[$name])) { if (!preg_match(self::NameRegexp, $name)) throw …; … }
 *     if ($component !== null) {
 *         if (!isset($parts[1])) return $component;
 *         elseif ($component instanceof IContainer) return $component->getComponent($parts[1], $throw);
 *         elseif ($throw) throw …"is not container and cannot have '$parts[1]' component";
 *     } elseif ($throw) throw …"Component with name '$name' does not exist";
 *
 * The limit-2 explode plus the recursion is a full explode as long as every hop is an IContainer,
 * which is exactly the walk below; ArrayAccess::offsetGet() delegates straight to getComponent()
 * (casting an int offset to string first), so offset access and getComponent() are one operation.
 *
 * THE EDGE CASES, all read off that body rather than off whichever copy looked most complete:
 *
 * - An EMPTY segment ('bar-', '-baz', 'bar--baz', or a bare '-') can never be in $components,
 *   because addComponent() applies the same NameRegexp - so the regexp arm fires and getComponent()
 *   throws NO MATTER what the container holds. That makes an invalid segment a PROVEN failure, the
 *   only stopping outcome the walk can be certain about, and it is why validation is eager: to
 *   reach a later segment at all, every earlier one must have been found, which an invalid name
 *   never is.
 * - A NUMERIC segment is an ordinary name: NameRegexp accepts digits, PHP folds the '0' array key
 *   to int 0, and addComponent() stores it under that key - so a name and an int offset meet. A
 *   replicator names each row by its integer index, which is what makes `$rep['0-field']` a real
 *   shape (row 0's 'field') rather than a miss, and isRowKey() is what tells the two apart.
 * - A segment is looked up BEFORE it is validated, so for a SINGLE segment "invalid" and "absent"
 *   are indistinguishable outcomes of one failed lookup; only a path makes invalidity independent
 *   of what the shape knows. ComponentPathWalk::OUTCOME_INVALID_NAME is therefore multi-segment
 *   only.
 */
final class ComponentPath
{

	/**
	 * Nette\ComponentModel\Container::NameRegexp, which is private there. addComponent() applies it
	 * to every name it stores and getComponent() to every segment it fails to find, so it is the
	 * boundary between "a name that could exist" and "a name that throws".
	 */
	private const NAME_PATTERN = '#^[a-zA-Z0-9_]+$#D';

	/** A replicator row key - Kdyby names each replica by its integer index, cast to a string key. */
	private const ROW_KEY_PATTERN = '~^\d++$~';

	/**
	 * @return non-empty-list<string>
	 */
	public static function split(string $name): array
	{
		return explode(IComponent::NameSeparator, $name);
	}

	public static function isValidSegment(string $segment): bool
	{
		return preg_match(self::NAME_PATTERN, $segment) === 1;
	}

	/**
	 * Whether addComponent() would accept this name rather than throwing
	 * `Nette\InvalidArgumentException: Component name must be non-empty alphanumeric string`. It is
	 * the SAME regexp isValidSegment() applies, deliberately: Nette checks NameRegexp once on the
	 * way in and once per unfound segment on the way out, so a registerable name and a walkable
	 * segment are one and the same predicate. The two are separate methods only because a whole
	 * PATH may legitimately contain the `-` separator that neither of them accepts — split() first,
	 * then ask this per segment, never ask it about a path.
	 */
	public static function isValidRegistrationName(string $name): bool
	{
		return self::isValidSegment($name);
	}

	/**
	 * A purely-decimal segment, which under a replicator is indistinguishable from a dynamically
	 * created row - unresolvable as an own child, resolvable as a row.
	 */
	public static function isRowKey(string $segment): bool
	{
		return preg_match(self::ROW_KEY_PATTERN, $segment) === 1;
	}

	/**
	 * isRowKey()'s counterpart for an offset whose VALUE is not statically known - the case a
	 * constant segment string can never answer, because there is no segment to split and match.
	 * `PHPStan\Type\Type::isDecimalIntegerString()` (PHPStan 2.2.2, backed by
	 * `Accessory\AccessoryDecimalIntegerStringType`) proves exactly the property that matters: its
	 * own docblock says yes() is "guaranteed to be cast to an integer in an array key" and no() is
	 * "guaranteed to stay as string". Ordinary code reaches yes() without any annotation - `(string)
	 * $i` is typed `decimal-int-string`, and so is a variable inside a `ctype_digit()` guard - which
	 * is what makes this worth having: `$rep[(string) $i]` used to degrade to IComponent.
	 *
	 * It is NOT isRowKey() lifted to types, and callers must not treat it as such. The library
	 * predicate models PHP's array-key coercion, while isRowKey() models the names a replicator
	 * actually generates, so they diverge at both ends: '-1' coerces to an int key (yes) but is not a
	 * row and not even a legal single segment, and '007' does not coerce (no) yet is all-digits and
	 * therefore indistinguishable from a row to isRowKey(). They agree on every name a replicator
	 * does generate ('0', '1', …). Every offset that HAS a constant value is therefore still decided
	 * by split() + isRowKey(); this answers only the offsets that have none.
	 *
	 * Yes and Maybe are the only useful answers. A no() proves the offset is not a row, but proving
	 * that yields no NAME, so the own-children arm still has nothing to look up - which is why no()
	 * is not acted on anywhere.
	 */
	public static function isRowKeyType(Type $offsetType): TrinaryLogic
	{
		return $offsetType->isInteger()->or($offsetType->isDecimalIntegerString());
	}

	/** Any channel at all, including the opaque componentTypes one. */
	public static function hasChild(FormShape $shape, string $name): bool
	{
		return self::hasShapedChild($shape, $name)
			|| array_key_exists($name, $shape->getComponentTypes());
	}

	/**
	 * The three channels that carry a shape of their own - the ones FormShapeProjector::offset() can
	 * resolve a type from, as opposed to componentTypes, which carries only a class name.
	 */
	public static function hasShapedChild(FormShape $shape, string $name): bool
	{
		return array_key_exists($name, $shape->getSlots())
			|| array_key_exists($name, $shape->getContainers())
			|| array_key_exists($name, $shape->getReplicators());
	}

	/**
	 * A name the shape proves DEFINITELY exists: a channel that holds it, whose presence for it is
	 * Certainty::HAPPENS on every path.
	 *
	 * The arm ORDER is the contract, not a formality. componentTypes is the SOLE record of a
	 * value-less control (a KIND_OMITTED addSubmit()) and a class-name COMPANION to a name one of the
	 * three shaped channels already owns, and its presence axis cannot mean both at once. Asking the
	 * shaped channels first settles it: whenever a shaped channel holds the name, that channel's
	 * presence is the answer and the componentTypes arm is unreachable for it; the last arm therefore
	 * only ever answers where componentTypes is the only record there is.
	 *
	 * Until the axis existed this method returned false for every componentTypes-only name, which made
	 * an UNCONDITIONALLY added submit button answer exactly like a conditional one, and
	 * `$form['save'] ?? null` kept a null arm for a control that provably exists. The naive fix -
	 * returning true on the mere EXISTENCE of a componentTypes entry - is the reason it stayed
	 * unfixed for so long: CompositionState accumulated a name into that map from any joined branch
	 * that added it, so it flipped the conditional case too and misinformed core's issetCheck() about
	 * nullability (confirmed live). What makes the last arm safe now is not the arm, it is
	 * CompositionState::joinComponentTypes()/foldComponentTypes() and FormShape::joinBranch() MEETING
	 * the certainty beside the class name, exactly as the shaped channels have always done.
	 */
	public static function hasDefiniteChild(FormShape $shape, string $name): bool
	{
		$slots = $shape->getSlots();
		if (isset($slots[$name])) {
			return $slots[$name]->getPresence() === Certainty::HAPPENS;
		}

		$containers = $shape->getContainers();
		if (isset($containers[$name])) {
			return ($shape->getContainerPresence()[$name] ?? Certainty::HAPPENS) === Certainty::HAPPENS;
		}

		$replicators = $shape->getReplicators();
		if (isset($replicators[$name])) {
			return ($shape->getReplicatorPresence()[$name] ?? Certainty::HAPPENS) === Certainty::HAPPENS;
		}

		return array_key_exists($name, $shape->getComponentTypes())
			&& $shape->componentTypePresence($name) === Certainty::HAPPENS;
	}

	/**
	 * The three-valued presence of one child, which is hasDefiniteChild() plus the one other
	 * definite answer a shape can give: a CLOSED shape holding the name in no channel proves it
	 * absent. That NEVER is the same proof FormShapeUnknownAccessRule reports absence from, so a
	 * consumer reading it here can never contradict the report the rule makes about the same name.
	 *
	 * It lives beside hasDefiniteChild() rather than in either consumer because both the shape walk
	 * (deciding whether a lazy read attaches) and the existence-check rule (deciding whether an
	 * isset is constant) need exactly this, and two spellings of "closed and absent" would be two
	 * places for the precedence to drift.
	 */
	public static function childPresence(FormShape $shape, string $name): string
	{
		if (self::hasDefiniteChild($shape, $name)) {
			return Certainty::HAPPENS;
		}

		return !$shape->getUnknown()->hasUnknown() && !self::hasChild($shape, $name)
			? Certainty::NEVER
			: Certainty::MAYBE;
	}

	/**
	 * hasDefiniteChild() over a whole path: every hop must be a definitely-attached container (or a
	 * definitely-attached replicator holding the leaf as an own child), and the leaf must be
	 * definitely present in whatever the walk landed in.
	 *
	 * @param non-empty-list<string> $segments
	 */
	public static function hasDefinitePath(FormShape $shape, array $segments): bool
	{
		$walk = self::walk($shape, $segments, true);

		$hop = $walk->getReplicatorHop();
		if ($hop !== null) {
			return self::replicatorHasDefinitePath($hop[0]->getInner(), $hop[0]->getOwn(), $hop[1]);
		}

		return $walk->isLeaf() && self::hasDefiniteChild($walk->getShape(), $walk->getSegment());
	}

	/**
	 * The decision Nette hands to the replicator's own getComponent(): a decimal name is a ROW,
	 * created on demand and therefore always present (which is why the row itself needs no proving -
	 * the same reason FormReplicatorType answers Yes for every integer offset), any other name is
	 * one of the replicator's OWN children.
	 *
	 * @param non-empty-list<string> $remainder
	 */
	public static function replicatorHasDefinitePath(
		FormShape $innerRow,
		FormShape $own,
		array $remainder
	): bool
	{
		if (self::isRowKey($remainder[0])) {
			$belowRow = array_slice($remainder, 1);

			return $belowRow === [] || self::hasDefinitePath($innerRow, $belowRow);
		}

		return self::hasDefinitePath($own, $remainder);
	}

	/**
	 * The walk itself: descend every segment but the last, report where it stopped. $segments is
	 * always non-empty (split() of any string yields at least one element).
	 *
	 * $requireDefinitePresence is the one axis consumers legitimately disagree on. A question about
	 * DEFINITE existence (FormShapeType::hasOffsetValueType(), which core's issetCheck() takes
	 * literally) must refuse a hop through a container that might not be attached; a question about
	 * a TYPE or about ABSENCE must not, because a MAYBE container still names real children and
	 * refusing there would lose the answer rather than sharpen it.
	 *
	 * @param non-empty-list<string> $segments
	 */
	public static function walk(
		FormShape $shape,
		array $segments,
		bool $requireDefinitePresence = false
	): ComponentPathWalk
	{
		$count = count($segments);

		// Eager, and sound because of it: an invalid segment is never in $components, so to have
		// reached it every earlier segment must have resolved and getComponent() must throw. The
		// message names the invalid segment rather than whichever earlier one the vendor happens to
		// fail on first - the verdict is the same either way.
		if ($count > 1) {
			foreach ($segments as $segment) {
				if (!self::isValidSegment($segment)) {
					return ComponentPathWalk::stopped(
						ComponentPathWalk::OUTCOME_INVALID_NAME,
						$shape,
						$segment,
					);
				}
			}
		}

		$current = $shape;
		for ($i = 0; $i < $count - 1; $i++) {
			$segment = $segments[$i];

			$replicators = $current->getReplicators();
			if (isset($replicators[$segment])) {
				if (
					$requireDefinitePresence
					&& ($current->getReplicatorPresence()[$segment] ?? Certainty::HAPPENS) !== Certainty::HAPPENS
				) {
					return ComponentPathWalk::stopped(
						ComponentPathWalk::OUTCOME_MAYBE_PRESENT,
						$current,
						$segment,
					);
				}

				// The whole remainder, not just a leaf: what the rest of the path means past a
				// replicator is the replicator's decision (row vs own child), not the walk's.
				return ComponentPathWalk::replicator(
					$current,
					$segment,
					$replicators[$segment],
					array_slice($segments, $i + 1),
				);
			}

			$containers = $current->getContainers();
			if (!isset($containers[$segment])) {
				return ComponentPathWalk::stopped(
					self::hasChild($current, $segment)
						? ComponentPathWalk::OUTCOME_NOT_CONTAINER
						: ComponentPathWalk::OUTCOME_MISSING,
					$current,
					$segment,
				);
			}

			if (
				$requireDefinitePresence
				&& ($current->getContainerPresence()[$segment] ?? Certainty::HAPPENS) !== Certainty::HAPPENS
			) {
				return ComponentPathWalk::stopped(
					ComponentPathWalk::OUTCOME_MAYBE_PRESENT,
					$current,
					$segment,
				);
			}

			$current = $containers[$segment];
		}

		return ComponentPathWalk::leaf($current, $segments[$count - 1]);
	}

}
