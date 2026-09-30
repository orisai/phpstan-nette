<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms;

use Nette\Forms\Controls\TextInput;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Forms\Shape\ComponentSlot;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use OriPhpstan\Nette\Forms\Shape\ReplicatorShape;
use OriPhpstan\Nette\Forms\Shape\UnknownInfo;
use OriPhpstan\Nette\Forms\Type\FormControlType;
use OriPhpstan\Nette\Forms\Type\FormReplicatorType;
use OriPhpstan\Nette\Forms\Type\FormShapeProjector;
use OriPhpstan\Nette\Forms\Type\FormShapeType;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ErrorType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\VerbosityLevel;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomReplicatorContainer;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;
use Tests\OriPhpstan\Nette\Toolkit\ShapeSnapshotAssertions;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;

final class FormReplicatorTypeTest extends PHPStanTestCase
{

	use VersionGroupGate;
	use ShapeSnapshotAssertions;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		self::getContainer();
	}

	private function innerRowShape(): FormShape
	{
		return new FormShape(
			FormContainer::class,
			['field' => new ComponentSlot('field', new StringType(), Certainty::HAPPENS, [])],
			[],
			[],
			new UnknownInfo(),
			[],
		);
	}

	private function ownShapeWithComponentType(string $name, string $class): FormShape
	{
		return new FormShape(null, [], [], [], new UnknownInfo(), [], [], [], [$name => $class]);
	}

	private function ownShapeWithSlot(string $name, string $presence): FormShape
	{
		return new FormShape(
			null,
			[$name => new ComponentSlot($name, new StringType(), $presence, [], TextInput::class)],
			[],
			[],
			new UnknownInfo(),
			[],
		);
	}

	private function type(FormShape $own): FormReplicatorType
	{
		return new FormReplicatorType(
			FormContainer::class,
			FormContainer::class,
			$this->innerRowShape(),
			$own,
		);
	}

	public function testIntegerOffsetHasOffsetValueTypeYes(): void
	{
		$type = $this->type(FormShape::empty());

		self::assertTrue($type->hasOffsetValueType(new ConstantIntegerType(0))->yes());
	}

	public function testIntegerOffsetResolvesToInnerRow(): void
	{
		$type = $this->type($this->ownShapeWithComponentType('addNode', CustomSubmitButton::class));

		$rowType = $type->getOffsetValueType(new ConstantIntegerType(0));
		self::assertInstanceOf(FormShapeType::class, $rowType);
		self::assertSame(FormContainer::class, $rowType->getClassName());
		self::assertShape($rowType->getFormShape(), <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			  field: *mixed*<*any*, string>,
			}
			OUTPUT, 'the integer offset resolves to the whole inner row, own children excluded');
	}

	public function testOwnChildSlotHasOffsetValueTypeYes(): void
	{
		$type = $this->type($this->ownShapeWithSlot('label', Certainty::HAPPENS));

		self::assertTrue($type->hasOffsetValueType(new ConstantStringType('label'))->yes());
	}

	/**
	 * An own child held as a SLOT resolves through the projector's control channel, unlike the
	 * componentTypes-only case below, which the projector cannot see at all.
	 */
	public function testOwnChildSlotResolvesToItsControlType(): void
	{
		$type = $this->type($this->ownShapeWithSlot('label', Certainty::HAPPENS));

		$ownType = $type->getOffsetValueType(new ConstantStringType('label'));
		self::assertInstanceOf(FormControlType::class, $ownType);
		self::assertSame(TextInput::class, $ownType->getClassName());
	}

	public function testOwnChildNameResolvesToItsOwnType(): void
	{
		$type = $this->type($this->ownShapeWithComponentType('addNode', CustomSubmitButton::class));

		$ownType = $type->getOffsetValueType(new ConstantStringType('addNode'));
		self::assertInstanceOf(ObjectType::class, $ownType);
		self::assertSame(CustomSubmitButton::class, $ownType->getClassName());
	}

	/**
	 * componentTypes carries no presence axis - a value-less own child (e.g. a KIND_OMITTED
	 * addSubmit()) must NOT contribute Yes at the hasOffsetValueType() level, even though
	 * getOffsetValueType() (previous test) still resolves it correctly for the positive case: core
	 * only takes the "always exists, never null" shortcut when hasOffsetValueType() itself answers
	 * Yes, so a false Yes here would misinform it regardless of what the projected type would be.
	 */
	public function testOwnChildComponentTypesOnlyEntryIsNotYes(): void
	{
		$type = $this->type($this->ownShapeWithComponentType('addNode', CustomSubmitButton::class));

		$result = $type->hasOffsetValueType(new ConstantStringType('addNode'));
		self::assertFalse($result->yes());
		self::assertFalse($result->no());
	}

	/**
	 * A conditionally-added own child (presence MAYBE) must not answer Yes either - it might not
	 * exist on some path.
	 */
	public function testOwnChildMaybePresentSlotIsNotYes(): void
	{
		$type = $this->type($this->ownShapeWithSlot('label', Certainty::MAYBE));

		$result = $type->hasOffsetValueType(new ConstantStringType('label'));
		self::assertFalse($result->yes());
		self::assertFalse($result->no());
	}

	/**
	 * The never-No contract: an unknown name is never confidently absent - a No here would
	 * double-report against FormShapeUnknownAccessRule (core's offsetAccess.notFound reports on a
	 * definite No, and our own rule already reports orisai.nette.forms.noSuchComponent for the same access).
	 */
	public function testUnknownNameNeverAnswersNo(): void
	{
		$type = $this->type($this->ownShapeWithComponentType('addNode', CustomSubmitButton::class));

		$result = $type->hasOffsetValueType(new ConstantStringType('nope'));
		self::assertFalse($result->no());
	}

	public function testUnknownNameDegradesToIComponentNeverRowNeverError(): void
	{
		$type = $this->type($this->ownShapeWithComponentType('addNode', CustomSubmitButton::class));

		$degraded = $type->getOffsetValueType(new ConstantStringType('nope'));
		self::assertNotInstanceOf(FormShapeType::class, $degraded);
		self::assertNotInstanceOf(ErrorType::class, $degraded);
	}

	public function testEqualsIsSensitiveToOwnShape(): void
	{
		$a = $this->type($this->ownShapeWithSlot('label', Certainty::HAPPENS));
		$b = $this->type($this->ownShapeWithSlot('label', Certainty::HAPPENS));
		$c = $this->type(FormShape::empty());

		self::assertTrue($a->equals($b));
		self::assertFalse($a->equals($c));
	}

	public function testDescribeUnaffectedByOwnShape(): void
	{
		$withOwn = $this->type($this->ownShapeWithSlot('label', Certainty::HAPPENS));
		$withoutOwn = $this->type(FormShape::empty());

		self::assertSame(
			$withoutOwn->describe(VerbosityLevel::precise()),
			$withOwn->describe(VerbosityLevel::precise()),
		);
	}

	/**
	 * The WRAPPED class must be the replicator's OWN recorded class, never the inner row's -
	 * ReplicatorMethodReturnTypeExtension matches on the replicator's class, so pairing this type
	 * with the row's leaves getContainers()/createOne() unresolved. This was built in two places and
	 * a fix landed in only one of them, so both entry points assert it here: the direct leaf
	 * (`$form['rep']`) and the separator path that reaches the same replicator mid-walk.
	 */
	public function testProjectorWrapsTheReplicatorsOwnRecordedClass(): void
	{
		$replicator = new ReplicatorShape($this->innerRowShape(), FormShape::empty());
		$shape = new FormShape(
			FormContainer::class,
			[],
			[],
			['rep' => $replicator],
			new UnknownInfo(),
			[],
			[],
			[],
			['rep' => CustomReplicatorContainer::class],
		);

		$leaf = FormShapeProjector::offset($shape, 'rep');
		self::assertInstanceOf(FormReplicatorType::class, $leaf);
		self::assertSame(CustomReplicatorContainer::class, $leaf->getClassName());

		$outer = new FormShape(
			FormContainer::class,
			[],
			['outer' => $shape],
			[],
			new UnknownInfo(),
			[],
		);

		$viaPath = FormShapeProjector::offsetPath($outer, ['outer', 'rep']);
		self::assertInstanceOf(FormReplicatorType::class, $viaPath);
		self::assertSame(CustomReplicatorContainer::class, $viaPath->getClassName());
	}

}
