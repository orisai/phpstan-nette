<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Type;

use Nette\Forms\Container as NetteContainer;
use OriPhpstan\Nette\Forms\Shape\ComponentPath;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use OriPhpstan\Nette\Forms\Shape\ReplicatorShape;
use PHPStan\Type\ErrorType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use function count;
use function ltrim;

final class FormShapeProjector
{

	/**
	 * The separator-joined path form of offset() below (`$form['filter']['rep-addNode']`), resolved
	 * through the shared ComponentPath::walk() every consumer of Nette's component-path semantics
	 * uses — this one asks it for a TYPE, FormShapeUnknownAccessRule asks it about ABSENCE and
	 * FormShapeType about DEFINITE existence, over the same one walk.
	 *
	 * A walk that reaches the leaf resolves it with offset(). A walk that reaches a REPLICATOR hands
	 * the remainder to FormReplicatorType::pathType(), which owns the row-vs-own-child decision
	 * Nette itself makes there (a decimal name is a dynamically created row, anything else is a
	 * control added straight onto addDynamic()'s return value) and its own never-ErrorType degrade.
	 * Anything else — an intermediate segment matching no container, a segment Nette's own
	 * NameRegexp rejects — returns null: degrade to whatever the wrapped class's own ArrayAccess
	 * stub answers, never a guess.
	 *
	 * The leaf is childType() ?? unknownLeaf(), in that order and not the other way round.
	 * childType() answers for every channel that NAMES a child — the three shaped ones AND
	 * componentTypes — and returns null only when no channel does, which is exactly the case
	 * offset() falls through on; so the two answers callers already rely on come out of the same
	 * single expression they always did (mixed for an open shape, ErrorType for a closed one),
	 * while a componentTypes-only child — a KIND_OMITTED addSubmit(), i.e. every submit button —
	 * stops being invisible here. Calling offset() first instead would keep it invisible, because
	 * offset()'s fall-through is total: it answers for a name it has never heard of.
	 *
	 * @param non-empty-list<string> $segments
	 */
	public static function offsetPath(FormShape $shape, array $segments): ?Type
	{
		$walk = ComponentPath::walk($shape, $segments);

		$hop = $walk->getReplicatorHop();
		if ($hop !== null) {
			$replicator = self::replicatorType($walk->getShape(), $walk->getSegment(), $hop[0]);

			return $replicator === null ? null : $replicator->pathType($hop[1]);
		}

		if (!$walk->isLeaf()) {
			return null;
		}

		$leafShape = $walk->getShape();

		return self::childType($leafShape, $walk->getSegment()) ?? self::unknownLeaf($leafShape);
	}

	/**
	 * The type of a name the shape KNOWS, over every channel that can carry one: the three shaped
	 * channels offset() resolves, plus componentTypes, which holds a class name and nothing else
	 * (a control added with no value slot — a KIND_OMITTED addSubmit() — lives only there). Null
	 * means no channel names the child, which is a cue to DEGRADE and never an absence verdict:
	 * that verdict is unknownLeaf()'s, and reporting it is FormShapeUnknownAccessRule's alone.
	 *
	 * A TYPE resolution only, and it stays presence-blind on purpose: a name any branch recorded
	 * resolves to its class here, whether or not every branch attached it. The presence question is
	 * answered one layer up, off FormShape::componentTypePresence(), by
	 * ComponentPath::hasDefiniteChild() and the two hasOffsetValueType() implementations that call it.
	 * Do not fold the two together — a conditionally-added button must still resolve its own class
	 * (that is what makes a method call on it check) while still widening through `?? null`.
	 *
	 * Both leaf resolutions in this extension go through here: offsetPath() above and
	 * FormReplicatorType::pathType(), which used to carry a second private spelling of this rule and
	 * was for a while the only one of the two that could see componentTypes at all.
	 */
	public static function childType(FormShape $shape, string $name): ?Type
	{
		if (ComponentPath::hasShapedChild($shape, $name)) {
			return self::offset($shape, $name);
		}

		$componentTypes = $shape->getComponentTypes();

		return isset($componentTypes[$name]) ? new ObjectType($componentTypes[$name]) : null;
	}

	/**
	 * What a name the shape holds in NO channel resolves to, and the one place that decision is
	 * spelled. An open shape cannot rule the name out, so it degrades to mixed; a CLOSED shape
	 * proves it absent, and the ErrorType is how that proof travels — FormShapeUnknownAccessRule
	 * reports the absence itself, and the ErrorType keeps the same access from also raising method
	 * or offset noise on top of that one report (pinned by Offset::g1_18(),
	 * G11ComponentPathEquivalenceType and FormShapeTypeNullBailStaysIComponent).
	 */
	private static function unknownLeaf(FormShape $shape): Type
	{
		return $shape->getUnknown()->hasUnknown() ? new MixedType() : new ErrorType();
	}

	/**
	 * The three SHAPED channels only — a name held solely by componentTypes falls through to
	 * unknownLeaf() here, exactly as it always has. Callers that want the componentTypes channel
	 * too ask childType(), which is a superset of this and delegates back to it.
	 *
	 * The slot and container channels are UNIONED when both hold the name, and the order of the four
	 * outcomes below is ContainerModel's leaf arm's order, arm for arm. Two branches can fill one name
	 * with a control in one and a container in the other, either can be the component actually
	 * attached, and returning whichever map was consulted first drops a real possibility on a coin
	 * toss. That arm has always unioned exactly these two (MUnionContainerBranchesResolves pins
	 * `TextInput|FormContainer{inner: string}` for it); this used to fold the same shape to
	 * `TextInput`, so one runtime lookup had two answers decided by nothing but whether the enclosing
	 * file happens to build a form.
	 *
	 * An arm that cannot name a class contributes NOTHING to the union rather than contributing mixed,
	 * which would swallow the other arm whole - the degrade is per-arm and stays below, where the
	 * channel that did answer is preferred over the channel that could not. That is what makes an
	 * OPAQUE slot beside a container resolve to the container's shape and not to the control class
	 * componentTypes happens to hold: the slot channel has no carrier for the name, so it does not get
	 * to shadow one, which is the same order the other channel has always used for the same collision
	 * (measured head-to-head, both channels: `FormContainer{inner: string}`).
	 *
	 * The REPLICATOR channel is deliberately NOT a third member of that union, and its arm stays the
	 * fall-through it is. ContainerModel's leaf arm reaches its own replicator branch only after both
	 * of the other two have declined and only through a componentTypes entry, so unioning a replicator
	 * in here would answer something the other channel does not — re-opening the divergence in the
	 * opposite direction, on a collision (`addDynamic('x')` in one branch, `addText('x')` in the
	 * other) nothing in the corpus or the fixture tree produces. Widening it is a change to BOTH
	 * channels' leaf order and belongs with one, not here.
	 *
	 * A slot whose VALUE type could not be read is opaque, and opaque is a statement about the value
	 * alone — the component is still whichever control class was added, and for these slots that
	 * class is recorded only in componentTypes. Returning mixed here regardless let the slots channel
	 * SHADOW componentTypes (hasShapedChild() is already true, so childType()'s own componentTypes
	 * arm can never be reached for the name) and threw the class away, against childType()'s promise
	 * to answer "over every channel that can carry one" — and against ContainerModel's leaf arm,
	 * which declines the slot for an opaque one and falls to componentTypes for exactly this reason,
	 * so the two channels disagreed about real SelectBox/TextInput controls. The bare ObjectType is
	 * the honest degrade: the class is known, the value shape is not, and FormShapeUnknownAccessRule
	 * still reports orisai.nette.forms.partiallyUnknown for the value axis on the same name.
	 *
	 * The container and replicator arms both wrap a CHILD shape, and both ask isUsableInnerShape()
	 * first for the same reason ContainerModel's leaf arm always did (containerChildType() below has
	 * the full statement of it for the container half). An unresolvable multiplier or replicator
	 * factory leaves a shape nothing can be claimed about, and the bare component class is the answer
	 * for it — the same answer the other channel gives, which is the point: the two used to say
	 * `IComponent` and `FormContainer{}` for one runtime lookup, decided by nothing but which file the
	 * expression was written in.
	 *
	 * This answers on the VALUE axis only, and a maybe-present child is therefore NOT widened with
	 * null on any of the three channels. The access being modelled is the THROWING one — Nette's
	 * Container::getComponent() throws for a name that is not attached rather than returning null
	 * (ComponentModelAccessDynamicReturnTypeExtension::createComponentFallback() removes NullType
	 * for exactly that reason on the offsetGet channel) — so `X|null` was never the runtime type of
	 * `$form['x']`; the null arm only made every later member access on a conditionally-added
	 * control report method.nonObject, property.nonObject or argument.type against a null that
	 * cannot reach it.
	 *
	 * Presence is carried on its own axis, twice, and neither reading needs the union: PHPStan's
	 * offset-existence question is answered by FormShapeType::hasOffsetValueType() /
	 * FormReplicatorType::hasOffsetValueType(), which is what actually keeps `$form['x'] ?? null`
	 * and isset() nullable (pinned by Offset::g1_20()/g1_24()/g1_25() and
	 * ComponentTypesOnlyOffset::conditionalSubmitStaysNullable()), and a provable absence is
	 * FormShapeUnknownAccessRule's sole report. Encoding it a third time here contradicted both.
	 */
	public static function offset(FormShape $shape, string $name): Type
	{
		$slotType = self::slotControlType($shape, $name);
		$containerType = self::containerChildType($shape, $name);
		if ($slotType !== null && $containerType !== null) {
			return TypeCombinator::union($containerType, $slotType);
		}

		if ($containerType !== null) {
			return $containerType;
		}

		if ($slotType !== null) {
			return $slotType;
		}

		$slots = $shape->getSlots();
		if (isset($slots[$name])) {
			return $slots[$name]->isTypeOpaque() ? self::classlessChild($shape, $name) : new MixedType();
		}

		$containers = $shape->getContainers();
		if (isset($containers[$name])) {
			return self::classlessChild($shape, $name);
		}

		$replicators = $shape->getReplicators();
		if (isset($replicators[$name])) {
			$replicator = $replicators[$name];
			$wrapped = self::isUsableInnerShape($replicator->getInner())
				? self::replicatorType($shape, $name, $replicator)
				: null;

			return $wrapped ?? self::classlessChild($shape, $name);
		}

		return self::unknownLeaf($shape);
	}

	/**
	 * The slot channel's own carrier for a name, and null for every reason the slot channel cannot
	 * produce one: no slot at all, a slot whose value type could not be read (opaque - the class then
	 * lives in componentTypes and classlessChild() is the answer offset() keeps for it), or a slot that
	 * recorded no control class to name. Null is a "this channel does not answer", never a degrade of
	 * its own, which is what lets offset() above union it with the container channel and still fall
	 * back to the exact same degrades it always gave when only one channel replies.
	 *
	 * A slot carries a LIST of control classes, because two branches can fill one name with two
	 * different controls, so this answers with one FormControlType per class and unions them - the same
	 * answer ContainerModel's own leaf arm gives for that shape. It must not collapse to a single
	 * carrier over a placeholder class name: an ObjectType over a class that does not exist is not a
	 * degrade, it is a wrong answer core takes literally and reports class.notFound against on every
	 * later method call (pinned by Offset::g1_23()).
	 */
	private static function slotControlType(FormShape $shape, string $name): ?Type
	{
		$slots = $shape->getSlots();
		if (!isset($slots[$name]) || $slots[$name]->isTypeOpaque()) {
			return null;
		}

		$slot = $slots[$name];
		$classes = $slot->getControlClasses();
		if ($classes === null || $classes === []) {
			return null;
		}

		$arms = [];
		foreach ($classes as $class) {
			$arms[] = new FormControlType(
				$class,
				$slot->getValueType(),
				$slot->getAcceptedSetSpec(),
				$slot->isNullable(),
			);
		}

		return count($arms) === 1 ? $arms[0] : TypeCombinator::union(...$arms);
	}

	/**
	 * The container channel's own carrier for a name, null when the shape holds no container for it or
	 * holds one whose class it could not name - the same two-way split slotControlType() above makes,
	 * for the same reason.
	 *
	 * isUsableInnerShape() decides which carrier, not whether there is one: a FormShapeType claims
	 * "these are its children, and there are no others", which an unresolvable multiplier factory's
	 * shape cannot support, and the bare component class is the honest answer for it. That answer is
	 * still a real carrier and still joins the union above - degrading an arm must not poison the whole
	 * union with mixed, only make that arm less precise.
	 */
	private static function containerChildType(FormShape $shape, string $name): ?Type
	{
		$containers = $shape->getContainers();
		if (!isset($containers[$name])) {
			return null;
		}

		$child = $containers[$name];
		$childClass = $child->getClassName();
		if ($childClass === null) {
			return null;
		}

		return self::isUsableInnerShape($child)
			? new FormShapeType($childClass, $child)
			: new ObjectType($childClass);
	}

	/**
	 * Whether a child shape carries enough to be WRAPPED, which is a different question from whether
	 * it exists. A FormShapeType/FormReplicatorType is a positive claim - "these are its children, and
	 * there are no others" - so a row or child shape with no class or with a class that is not a forms
	 * container has nothing to claim and must not become one; the bare component class is the honest
	 * answer, and the caller that cannot name a class at all degrades further. The three
	 * ...StaysIComponent fixtures are what this predicate holds up: an unresolvable
	 * multiplier/replicator factory leaves a shape with no children and no way to learn any, and
	 * rendering that as `FormContainer{}` reads as a container that owns nothing rather than one
	 * nothing is known about.
	 *
	 * EMPTY and OPEN is the uninformative case, not empty alone. A closed shape with no children is a
	 * PROOF - the walk saw the whole construction and it added nothing - and it is the proof
	 * FormShapeUnknownAccessRule reports `$form['sub']['nope']` off (ExistenceCheck::ec28(), where
	 * `addContainer('sub')` is the entire statement). Dropping that shape for the bare class would take
	 * the rule's only carrier away with it and the absence would go unreported, which is the one thing
	 * a degrade may never do.
	 *
	 * ONE definition, because both channels ask it of the same shapes: ContainerModel::walk() asks it
	 * before wrapping a container, a descended row and a replicator leaf, and offset() asks it in the
	 * two arms that build the same carriers for the same shape one hop earlier. It lived only in
	 * ContainerModel while the projector had no gate at all, which is why the two channels answered
	 * `IComponent` and `FormContainer{}` for one expression.
	 */
	public static function isUsableInnerShape(FormShape $inner): bool
	{
		$className = $inner->getClassName();
		if ($className === null) {
			return false;
		}

		if (!(new ObjectType(NetteContainer::class))->isSuperTypeOf(
			new ObjectType(ltrim($className, '\\')),
		)->yes()
		) {
			return false;
		}

		if ($inner->getContainers() !== []
			|| $inner->getSlots() !== []
			|| $inner->getReplicators() !== []
			|| $inner->getComponentTypes() !== []
		) {
			return true;
		}

		return !$inner->getUnknown()->hasUnknown();
	}

	/**
	 * The degrade all three of offset()'s arms share, for a name a channel DOES hold but could not
	 * name a class for. It is not an absence question — the child is there — so unknownLeaf() must
	 * not answer it: on a closed shape that would hand back an ErrorType for a child the walk itself
	 * recorded. componentTypes is read for a TYPE only and never for presence (childType()'s own
	 * docblock has the full reason), and mixed is the floor when even that holds nothing.
	 *
	 * What this replaced, in all three arms, was an ObjectType over a class literally named `\mixed`,
	 * which does not exist. That is not a degrade: core takes the class name literally and reports
	 * class.notFound against every later member access on it, five such findings at a time when the
	 * slot arm did it (the defect Offset::g1_23() pins the fix for). None of the three remaining
	 * spellings could be reached — a container/replicator entry whose class is unknown is either
	 * overwritten by the child walk or dropped for an opaque slot before any read — so this is the
	 * structural guarantee rather than an observed fix: an arm that cannot name a class no longer has
	 * a way to invent one.
	 */
	private static function classlessChild(FormShape $shape, string $name): Type
	{
		$componentTypes = $shape->getComponentTypes();

		return isset($componentTypes[$name]) ? new ObjectType($componentTypes[$name]) : new MixedType();
	}

	/**
	 * The ONE place a FormReplicatorType is built out of a shape, because it was built in two and a
	 * fix landed in only one of them. Public for the second former site - ContainerModel's leaf arm,
	 * the channel every .latte goes through - which decides WHETHER to build one (it needs a usable
	 * inner shape and a known component class) but no longer carries its own recipe for HOW.
	 *
	 * The wrapped class must be the replicator's OWN class (e.g. Kdyby\Replicator\Container), not the
	 * inner row's - FormReplicatorType wraps the former so getContainers()/createOne() resolve;
	 * pairing it with the row's class instead leaves the wrapped class without those methods. The
	 * componentTypes entry is that own class, and the inner row's class is the fallback behind it for
	 * the callers that have not already proven one is there.
	 *
	 * Null when either class is unknown, and never a type over a placeholder class name: a
	 * FormReplicatorType is a wrapped class plus a ROW class, so a replicator missing either cannot
	 * be one without inventing a class that does not exist - which core reads literally and reports
	 * class.notFound against on every later member access, exactly the way Offset::g1_23() pins for
	 * the slot channel. The callers degrade instead, each to the answer it already gives for a name
	 * it cannot resolve, and that is the same IComponent ContainerModel's leaf arm has always given
	 * here - it declines an unusable inner shape before it ever asks for a type.
	 */
	public static function replicatorType(
		FormShape $shape,
		string $name,
		ReplicatorShape $replicator
	): ?FormReplicatorType
	{
		$inner = $replicator->getInner();
		$rowClass = $inner->getClassName();
		$replicatorClass = $shape->getComponentTypes()[$name] ?? $rowClass;
		if ($replicatorClass === null || $rowClass === null) {
			return null;
		}

		return new FormReplicatorType($replicatorClass, $rowClass, $inner, $replicator->getOwn());
	}

}
