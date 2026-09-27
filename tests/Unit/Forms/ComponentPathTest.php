<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms;

use Nette\ComponentModel\IComponent;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Forms\Shape\ComponentPath;
use OriPhpstan\Nette\Forms\Shape\ComponentPathWalk;
use OriPhpstan\Nette\Forms\Shape\ComponentSlot;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use OriPhpstan\Nette\Forms\Shape\ReplicatorShape;
use OriPhpstan\Nette\Forms\Shape\UnknownInfo;
use PHPStan\Type\Accessory\AccessoryDecimalIntegerStringType;
use PHPStan\Type\Accessory\AccessoryNumericStringType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\PathContainer;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Throwable;
use function sprintf;

/**
 * The shared component-path semantics, pinned against the vendor they are derived from rather than
 * against whichever of the four former copies looked most complete. Several cases below drive the
 * REAL Nette\ComponentModel\Container to establish what the runtime does, then assert the walk
 * agrees - the vendor is the oracle, not this extension's own history.
 */
final class ComponentPathTest extends BaseTestCase
{

	private const CLASS_NAME = 'Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm';

	/**
	 * @return iterable<string, array{string, list<string>}>
	 */
	public static function splitProvider(): iterable
	{
		yield 'single' => ['bar', ['bar']];
		yield 'pair' => ['bar-baz', ['bar', 'baz']];
		yield 'triple' => ['a-b-c', ['a', 'b', 'c']];
		yield 'trailing separator' => ['bar-', ['bar', '']];
		yield 'leading separator' => ['-baz', ['', 'baz']];
		yield 'doubled separator' => ['bar--baz', ['bar', '', 'baz']];
		yield 'only separator' => ['-', ['', '']];
		yield 'empty' => ['', ['']];
		yield 'numeric' => ['0-field', ['0', 'field']];
		yield 'underscores are one segment' => ['a_b', ['a_b']];
	}

	/**
	 * @param list<string> $expected
	 *
	 * @dataProvider splitProvider
	 */
	public function testSplit(string $name, array $expected): void
	{
		self::assertSame($expected, ComponentPath::split($name));
	}

	public function testSplitUsesTheVendorSeparator(): void
	{
		self::assertSame(['a', 'b'], ComponentPath::split('a' . IComponent::NameSeparator . 'b'));
	}

	// ---------------------------------------------------------------- segment validity

	/**
	 * @return iterable<string, array{string, bool}>
	 */
	public static function segmentProvider(): iterable
	{
		yield 'alphanumeric' => ['bar', true];
		yield 'digits' => ['0', true];
		yield 'underscore' => ['a_b', true];
		yield 'mixed case and digits' => ['Bar9', true];
		yield 'empty' => ['', false];
		yield 'space' => ['a b', false];
		yield 'dot' => ['a.b', false];
		yield 'separator itself' => ['-', false];
	}

	/** @dataProvider segmentProvider */
	public function testIsValidSegmentMatchesTheVendorNameRegexp(string $segment, bool $valid): void
	{
		self::assertSame($valid, ComponentPath::isValidSegment($segment));

		// The vendor is the oracle: addComponent() applies the SAME private NameRegexp, so a name it
		// accepts is exactly a name that could ever be found by getComponent().
		$container = new PathContainer();
		$accepted = true;
		try {
			$container[$segment] = new PathContainer();
		} catch (Throwable $e) {
			$accepted = false;
		}

		self::assertSame($valid, $accepted, sprintf("vendor disagreed about segment '%s'", $segment));
	}

	/**
	 * @return iterable<string, array{string, bool}>
	 */
	public static function rowKeyProvider(): iterable
	{
		yield 'zero' => ['0', true];
		yield 'multi digit' => ['12', true];
		yield 'leading zero' => ['007', true];
		yield 'empty' => ['', false];
		yield 'digit then letter' => ['0a', false];
		yield 'letter' => ['addNode', false];
		yield 'joined path is not a row key on its own' => ['0-field', false];
	}

	/** @dataProvider rowKeyProvider */
	public function testIsRowKey(string $segment, bool $expected): void
	{
		self::assertSame($expected, ComponentPath::isRowKey($segment));
	}

	/**
	 * @return iterable<string, array{Type, string}>
	 */
	public static function rowKeyTypeProvider(): iterable
	{
		$decimal = new IntersectionType([new StringType(), new AccessoryDecimalIntegerStringType()]);
		$nonDecimal = new IntersectionType([new StringType(), new AccessoryDecimalIntegerStringType(true)]);
		$numeric = new IntersectionType([new StringType(), new AccessoryNumericStringType()]);

		yield 'int' => [new IntegerType(), 'Yes'];
		yield 'decimal-int-string' => [$decimal, 'Yes'];
		yield 'literal row name' => [new ConstantStringType('0'), 'Yes'];
		yield 'plain string' => [new StringType(), 'Maybe'];
		yield 'numeric-string' => [$numeric, 'Maybe'];
		yield 'int or string' => [new UnionType([new IntegerType(), new StringType()]), 'Maybe'];
		yield 'non-decimal-int-string' => [$nonDecimal, 'No'];
		yield 'literal own-child name' => [new ConstantStringType('addNode'), 'No'];
	}

	/** @dataProvider rowKeyTypeProvider */
	public function testIsRowKeyType(Type $offsetType, string $expected): void
	{
		self::assertSame($expected, ComponentPath::isRowKeyType($offsetType)->describe());
	}

	/**
	 * The two predicates are deliberately NOT the same rule, and this pins the two names where they
	 * disagree so the divergence cannot be "cleaned up" into a single one: PHP's array-key coercion
	 * takes '-1' to an int key and leaves '007' a string, while a replicator generates neither name.
	 * Every offset carrying a constant value is decided by split() + isRowKey() precisely because of
	 * this, so the disagreement never reaches an answer.
	 */
	public function testIsRowKeyTypeDivergesFromIsRowKeyOnNamesAReplicatorNeverGenerates(): void
	{
		self::assertFalse(ComponentPath::isRowKey('-1'));
		self::assertSame('Yes', ComponentPath::isRowKeyType(new ConstantStringType('-1'))->describe());

		self::assertTrue(ComponentPath::isRowKey('007'));
		self::assertSame('No', ComponentPath::isRowKeyType(new ConstantStringType('007'))->describe());
	}

	// ---------------------------------------------------------------- the walk

	public function testSingleSegmentIsAlreadyTheLeaf(): void
	{
		$shape = self::shapeWithSlot('a');
		$walk = ComponentPath::walk($shape, ['a']);

		self::assertSame(ComponentPathWalk::OUTCOME_LEAF, $walk->getOutcome());
		self::assertSame('a', $walk->getSegment());
		self::assertSame($shape, $walk->getShape());
	}

	public function testDescendsThroughContainers(): void
	{
		$inner = self::shapeWithSlot('b');
		$shape = new FormShape(self::CLASS_NAME, [], ['a' => $inner], [], new UnknownInfo(), []);

		$walk = ComponentPath::walk($shape, ['a', 'b']);

		self::assertSame(ComponentPathWalk::OUTCOME_LEAF, $walk->getOutcome());
		self::assertSame($inner, $walk->getShape());
		self::assertSame('b', $walk->getSegment());
	}

	public function testDescendsThroughTwoContainers(): void
	{
		$innermost = self::shapeWithSlot('c');
		$middle = new FormShape(null, [], ['b' => $innermost], [], new UnknownInfo(), []);
		$shape = new FormShape(self::CLASS_NAME, [], ['a' => $middle], [], new UnknownInfo(), []);

		$walk = ComponentPath::walk($shape, ['a', 'b', 'c']);

		self::assertSame(ComponentPathWalk::OUTCOME_LEAF, $walk->getOutcome());
		self::assertSame($innermost, $walk->getShape());
		self::assertSame('c', $walk->getSegment());
	}

	public function testMissingIntermediateSegment(): void
	{
		$shape = self::shapeWithSlot('a');
		$walk = ComponentPath::walk($shape, ['nope', 'x']);

		self::assertSame(ComponentPathWalk::OUTCOME_MISSING, $walk->getOutcome());
		self::assertSame('nope', $walk->getSegment());
		self::assertSame($shape, $walk->getShape());
	}

	/**
	 * A known name that is not traversable stops the walk WITHOUT condemning it: the shape carries
	 * no reflection to prove a control class is not itself an IContainer, and Nette only throws for
	 * one that really is not.
	 */
	public function testKnownButNonTraversableIntermediateSegment(): void
	{
		$shape = self::shapeWithSlot('a');
		$walk = ComponentPath::walk($shape, ['a', 'x']);

		self::assertSame(ComponentPathWalk::OUTCOME_NOT_CONTAINER, $walk->getOutcome());
		self::assertSame('a', $walk->getSegment());
	}

	public function testComponentTypesOnlyIntermediateSegmentIsNotContainerRatherThanMissing(): void
	{
		$shape = new FormShape(
			self::CLASS_NAME,
			[],
			[],
			[],
			new UnknownInfo(),
			[],
			[],
			[],
			['save' => 'Nette\Forms\Controls\SubmitButton'],
		);

		$walk = ComponentPath::walk($shape, ['save', 'x']);

		self::assertSame(ComponentPathWalk::OUTCOME_NOT_CONTAINER, $walk->getOutcome());
	}

	/**
	 * The walk stops AT the replicator and hands over the whole remainder: what the rest of the path
	 * means (a row, an own child) is the replicator's decision, not the walk's.
	 */
	public function testStopsAtAReplicatorWithTheWholeRemainder(): void
	{
		$own = self::shapeWithSlot('addNode');
		$replicator = new ReplicatorShape(FormShape::empty(), $own);
		$shape = new FormShape(self::CLASS_NAME, [], [], ['rep' => $replicator], new UnknownInfo(), []);

		$walk = ComponentPath::walk($shape, ['rep', 'addNode']);

		self::assertSame(ComponentPathWalk::OUTCOME_REPLICATOR, $walk->getOutcome());
		self::assertSame('rep', $walk->getSegment());
		self::assertSame($shape, $walk->getShape());
		self::assertSame([$replicator, ['addNode']], $walk->getReplicatorHop());

		$deeper = ComponentPath::walk($shape, ['rep', '0', 'x']);

		self::assertSame(ComponentPathWalk::OUTCOME_REPLICATOR, $deeper->getOutcome());
		self::assertSame([$replicator, ['0', 'x']], $deeper->getReplicatorHop());
	}

	public function testOnlyTheReplicatorOutcomeCarriesAHop(): void
	{
		self::assertNull(ComponentPath::walk(self::shapeWithSlot('a'), ['a'])->getReplicatorHop());
		self::assertNull(ComponentPath::walk(self::shapeWithSlot('a'), ['nope', 'x'])->getReplicatorHop());
	}

	/**
	 * @return iterable<string, array{non-empty-list<string>, string}>
	 */
	public static function invalidPathProvider(): iterable
	{
		yield 'trailing separator' => [['a', ''], ''];
		yield 'leading separator' => [['', 'a'], ''];
		yield 'doubled separator' => [['a', '', 'b'], ''];
		yield 'only separator' => [['', ''], ''];
		yield 'space in a later segment' => [['a', 'b c'], 'b c'];
	}

	/**
	 * @param non-empty-list<string> $segments
	 *
	 * @dataProvider invalidPathProvider
	 */
	public function testInvalidSegmentStopsAMultiSegmentWalk(array $segments, string $offending): void
	{
		$walk = ComponentPath::walk(self::shapeWithSlot('a'), $segments);

		self::assertSame(ComponentPathWalk::OUTCOME_INVALID_NAME, $walk->getOutcome());
		self::assertSame($offending, $walk->getSegment());
	}

	/**
	 * Validation is EAGER, and sound because of it: an invalid segment is never in $components, so
	 * to have reached it every earlier segment must have resolved - which means getComponent()
	 * throws whatever the container holds. This drives the real vendor container to prove it.
	 *
	 * @dataProvider invalidNameProvider
	 */
	public function testTheVendorAlwaysThrowsForAnInvalidSegment(string $name): void
	{
		$container = new PathContainer();
		$container['a'] = new PathContainer();

		$this->expectException(Throwable::class);
		$container[$name];
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function invalidNameProvider(): iterable
	{
		yield 'trailing separator on a real container' => ['a-'];
		yield 'leading separator' => ['-a'];
		yield 'doubled separator' => ['a--b'];
		yield 'only separator' => ['-'];
		yield 'trailing separator on a missing container' => ['nope-'];
	}

	/**
	 * With a SINGLE segment "invalid" and "absent" are the same failed lookup, so the walk reports
	 * the leaf and leaves the absence machinery to own it - exactly as before this consolidation.
	 */
	public function testSingleInvalidSegmentIsStillALeaf(): void
	{
		$walk = ComponentPath::walk(self::shapeWithSlot('a'), ['a b']);

		self::assertSame(ComponentPathWalk::OUTCOME_LEAF, $walk->getOutcome());
		self::assertSame('a b', $walk->getSegment());
	}

	// ---------------------------------------------------------------- presence axis

	public function testMaybePresentContainerOnlyStopsThePresenceRequiringWalk(): void
	{
		$inner = self::shapeWithSlot('b');
		$shape = new FormShape(
			self::CLASS_NAME,
			[],
			['a' => $inner],
			[],
			new UnknownInfo(),
			[],
			['a' => Certainty::MAYBE],
		);

		self::assertSame(
			ComponentPathWalk::OUTCOME_LEAF,
			ComponentPath::walk($shape, ['a', 'b'])->getOutcome(),
		);
		self::assertSame(
			ComponentPathWalk::OUTCOME_MAYBE_PRESENT,
			ComponentPath::walk($shape, ['a', 'b'], true)->getOutcome(),
		);
	}

	public function testMaybePresentReplicatorOnlyStopsThePresenceRequiringWalk(): void
	{
		$replicator = new ReplicatorShape(FormShape::empty(), self::shapeWithSlot('addNode'));
		$shape = new FormShape(
			self::CLASS_NAME,
			[],
			[],
			['rep' => $replicator],
			new UnknownInfo(),
			[],
			[],
			['rep' => Certainty::MAYBE],
		);

		self::assertSame(
			ComponentPathWalk::OUTCOME_REPLICATOR,
			ComponentPath::walk($shape, ['rep', 'addNode'])->getOutcome(),
		);
		self::assertSame(
			ComponentPathWalk::OUTCOME_MAYBE_PRESENT,
			ComponentPath::walk($shape, ['rep', 'addNode'], true)->getOutcome(),
		);
	}

	// ---------------------------------------------------------------- derived predicates

	public function testHasDefinitePath(): void
	{
		$inner = self::shapeWithSlot('b');
		$shape = new FormShape(self::CLASS_NAME, [], ['a' => $inner], [], new UnknownInfo(), []);

		self::assertTrue(ComponentPath::hasDefinitePath($shape, ['a', 'b']));
		self::assertTrue(ComponentPath::hasDefinitePath($shape, ['a']));
		self::assertFalse(ComponentPath::hasDefinitePath($shape, ['a', 'nope']));
		self::assertFalse(ComponentPath::hasDefinitePath($shape, ['nope', 'b']));
		self::assertFalse(ComponentPath::hasDefinitePath($shape, ['a', '']));
	}

	public function testHasDefinitePathIntoAReplicatorOwnChild(): void
	{
		$replicator = new ReplicatorShape(FormShape::empty(), self::shapeWithSlot('addNode'));
		$shape = new FormShape(self::CLASS_NAME, [], [], ['rep' => $replicator], new UnknownInfo(), []);

		self::assertTrue(ComponentPath::hasDefinitePath($shape, ['rep', 'addNode']));
		self::assertFalse(ComponentPath::hasDefinitePath($shape, ['rep', 'nope']));
		self::assertFalse(ComponentPath::hasDefinitePath($shape, ['rep', 'addNode', 'deeper']));
	}

	/**
	 * A row is created on demand, so the row itself always exists (the same reason
	 * FormReplicatorType answers Yes for every integer offset); only what is asked OF it needs
	 * proving.
	 */
	public function testHasDefinitePathIntoAReplicatorRow(): void
	{
		$replicator = new ReplicatorShape(self::shapeWithSlot('x'), self::shapeWithSlot('addNode'));
		$shape = new FormShape(self::CLASS_NAME, [], [], ['rep' => $replicator], new UnknownInfo(), []);

		self::assertTrue(ComponentPath::hasDefinitePath($shape, ['rep', '0']));
		self::assertTrue(ComponentPath::hasDefinitePath($shape, ['rep', '12']));
		self::assertTrue(ComponentPath::hasDefinitePath($shape, ['rep', '0', 'x']));
		self::assertFalse(ComponentPath::hasDefinitePath($shape, ['rep', '0', 'nope']));

		// The row shape and the own shape are different name sets, and a decimal segment picks the
		// row one - an own child's name is never looked for in a row and vice versa.
		self::assertFalse(ComponentPath::hasDefinitePath($shape, ['rep', '0', 'addNode']));
		self::assertFalse(ComponentPath::hasDefinitePath($shape, ['rep', 'x']));
	}

	public function testHasDefinitePathRefusesAMaybePresentLeaf(): void
	{
		$shape = new FormShape(
			self::CLASS_NAME,
			['a' => new ComponentSlot('a', new StringType(), Certainty::MAYBE, [])],
			[],
			[],
			new UnknownInfo(),
			[],
		);

		self::assertFalse(ComponentPath::hasDefinitePath($shape, ['a']));
	}

	/**
	 * A MAYBE-present container or replicator is no more definite as a LEAF than as a hop - presence
	 * is checked on whichever channel holds the name, not only on the slot one.
	 */
	public function testHasDefiniteChildRefusesAMaybePresentContainerOrReplicator(): void
	{
		$container = new FormShape(
			self::CLASS_NAME,
			[],
			['a' => self::shapeWithSlot('b')],
			[],
			new UnknownInfo(),
			[],
			['a' => Certainty::MAYBE],
		);

		self::assertFalse(ComponentPath::hasDefiniteChild($container, 'a'));
		self::assertFalse(ComponentPath::hasDefinitePath($container, ['a']));

		$replicator = new FormShape(
			self::CLASS_NAME,
			[],
			[],
			['rep' => new ReplicatorShape(FormShape::empty(), FormShape::empty())],
			new UnknownInfo(),
			[],
			[],
			['rep' => Certainty::MAYBE],
		);

		self::assertFalse(ComponentPath::hasDefiniteChild($replicator, 'rep'));
		self::assertFalse(ComponentPath::hasDefinitePath($replicator, ['rep']));
	}

	/**
	 * componentTypes is a known child for the traversability question - which is what keeps a
	 * value-less control (an addSubmit()) out of the does-not-exist arm - and it is NOT a shaped one.
	 * Neither answer depends on presence.
	 *
	 * The DEFINITE answer does, and this is the shape with no presence axis recorded for the name at
	 * all: a hand-built double, or anything a producer a later refactor forgets hands over. It must
	 * answer false, because a missing entry is read as MAYBE - the whole reason
	 * FormShape::componentTypePresence() defaults the opposite way from its container and replicator
	 * siblings.
	 */
	public function testComponentTypesWithNoPresenceRecordedIsAKnownChildButNotADefiniteOne(): void
	{
		$shape = self::submitOnly(null);

		self::assertTrue(ComponentPath::hasChild($shape, 'save'));
		self::assertFalse(ComponentPath::hasShapedChild($shape, 'save'));
		self::assertFalse(ComponentPath::hasDefiniteChild($shape, 'save'));
		self::assertFalse(ComponentPath::hasDefinitePath($shape, ['save']));
	}

	/**
	 * The axis, at both ends, on the one child kind componentTypes is the SOLE record of. A submit
	 * button every path attached is definite; one only some path attached is not, and the difference
	 * between those two answers is the entire point of the axis.
	 *
	 * This is also the guard against the naive fix. Making hasDefiniteChild() answer off
	 * array_key_exists() on componentTypes alone passes the HAPPENS case below and fails the MAYBE
	 * one, which is exactly the live misinformation that kept the axis unbuilt: core's issetCheck()
	 * takes a Yes literally, so a conditionally-added control would resolve as non-nullable through
	 * `?? null`.
	 */
	public function testComponentTypesPresenceDecidesTheDefiniteAnswer(): void
	{
		$definite = self::submitOnly(Certainty::HAPPENS);
		self::assertTrue(ComponentPath::hasDefiniteChild($definite, 'save'));
		self::assertTrue(ComponentPath::hasDefinitePath($definite, ['save']));

		$conditional = self::submitOnly(Certainty::MAYBE);
		self::assertTrue(ComponentPath::hasChild($conditional, 'save'));
		self::assertFalse(ComponentPath::hasDefiniteChild($conditional, 'save'));
		self::assertFalse(ComponentPath::hasDefinitePath($conditional, ['save']));
	}

	/**
	 * The read ORDER that resolves the two roles componentTypes plays. Here the name is BOTH a slot
	 * and a componentTypes entry - the KIND_UNKNOWN_TYPE shape, where the map is a class-name
	 * companion rather than the sole record - and the two axes are deliberately set to disagree. The
	 * slot must win in both directions, or the companion role would be answering a presence question
	 * some other channel already owns.
	 */
	public function testAShapedChannelOutranksTheComponentTypesAxisForTheSameName(): void
	{
		$slotDefinite = new FormShape(
			self::CLASS_NAME,
			['save' => new ComponentSlot('save', new StringType(), Certainty::HAPPENS, [])],
			[],
			[],
			new UnknownInfo(),
			[],
			[],
			[],
			['save' => 'Nette\Forms\Controls\SubmitButton'],
			['save' => Certainty::MAYBE],
		);
		self::assertTrue(ComponentPath::hasDefiniteChild($slotDefinite, 'save'));

		$slotConditional = new FormShape(
			self::CLASS_NAME,
			['save' => new ComponentSlot('save', new StringType(), Certainty::MAYBE, [])],
			[],
			[],
			new UnknownInfo(),
			[],
			[],
			[],
			['save' => 'Nette\Forms\Controls\SubmitButton'],
			['save' => Certainty::HAPPENS],
		);
		self::assertFalse(ComponentPath::hasDefiniteChild($slotConditional, 'save'));
	}

	private static function submitOnly(?string $presence): FormShape
	{
		return new FormShape(
			self::CLASS_NAME,
			[],
			[],
			[],
			new UnknownInfo(),
			[],
			[],
			[],
			['save' => 'Nette\Forms\Controls\SubmitButton'],
			$presence === null ? [] : ['save' => $presence],
		);
	}

	// ---------------------------------------------------------------- vendor equivalence

	/**
	 * The whole point of the shared walk: `$foo['bar-baz']`, `$foo['bar']['baz']`,
	 * `getComponent('bar-baz')` and `getComponent('bar')->getComponent('baz')` are ONE runtime
	 * lookup. Driven against the real Nette container so the claim is the vendor's, not ours.
	 */
	public function testTheFourSpellingsAreOneVendorLookup(): void
	{
		$root = new PathContainer();
		$outer = new PathContainer();
		$root['bar'] = $outer;
		$leaf = new PathContainer();
		$outer['baz'] = $leaf;

		// getComponent() is the subject here, not a style choice: offsetGet() delegates to it, and the
		// point of the assertion is that the delegation makes the four spellings indistinguishable.
		self::assertSame($leaf, $root->getComponent('bar-baz'));

		$mid = $root->getComponent('bar');
		self::assertInstanceOf(PathContainer::class, $mid);
		self::assertSame($leaf, $mid->getComponent('baz'));

		self::assertSame($leaf, $root['bar-baz']);
		self::assertSame($leaf, $root['bar']['baz']);
	}

	/**
	 * A numeric name is an ordinary component name to Nette (NameRegexp accepts digits) and PHP
	 * folds the array key to an int, which is exactly why a replicator's index-keyed rows are
	 * reachable by string - `$rep['0-field']` is a real shape, not a miss.
	 */
	public function testANumericSegmentIsARealVendorPath(): void
	{
		$root = new PathContainer();
		$row = new PathContainer();
		$root['0'] = $row;
		$field = new PathContainer();
		$row['field'] = $field;

		self::assertSame($field, $root['0-field']);
		self::assertSame($row, $root['0']);

		// An INT offset lands on the same component: ArrayAccess::offsetGet() casts it to a string and
		// PHP folded the '0' array key to int 0 when addComponent() stored it.
		$byInt = $root[0];
		self::assertInstanceOf(PathContainer::class, $byInt);
		self::assertSame($field, $byInt['field']);
	}

	private static function shapeWithSlot(string $name): FormShape
	{
		return new FormShape(
			self::CLASS_NAME,
			[$name => new ComponentSlot($name, new StringType(), Certainty::HAPPENS, [])],
			[],
			[],
			new UnknownInfo(),
			[],
		);
	}

}
