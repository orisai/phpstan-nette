<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms;

use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Forms\Shape\ComponentSlot;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use OriPhpstan\Nette\Forms\Shape\ReplicatorShape;
use OriPhpstan\Nette\Forms\Shape\UnknownInfo;
use OriPhpstan\Nette\Forms\Type\FormShapeType;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\VerbosityLevel;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton;

final class FormShapeTypeTest extends PHPStanTestCase
{

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		self::getContainer();
	}

	private function type(string $class, FormShape $shape): FormShapeType
	{
		return new FormShapeType($class, $shape);
	}

	private function shape(string $class): FormShape
	{
		return new FormShape(
			$class,
			['a' => new ComponentSlot('a', new StringType(), Certainty::HAPPENS, [])],
			[],
			[],
			new UnknownInfo(),
			[],
		);
	}

	public function testDescribeHasClassPrefixAndBody(): void
	{
		$t = $this->type(
			'Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm',
			$this->shape('Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm'),
		);
		self::assertSame(
			'Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: string}',
			$t->describe(VerbosityLevel::precise()),
		);
	}

	public function testStrictlyAdditive(): void
	{
		$t = $this->type(
			'Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm',
			$this->shape('Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm'),
		);
		$plain = new ObjectType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm');
		self::assertTrue($plain->isSuperTypeOf($t)->yes());
		self::assertTrue($plain->accepts($t, true)->yes());
	}

	public function testEqualsIsShapeSensitive(): void
	{
		$a = $this->type(
			'Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm',
			$this->shape('Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm'),
		);
		$b = $this->type(
			'Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm',
			$this->shape('Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm'),
		);
		$c = $this->type(
			'Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm',
			FormShape::empty('Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm'),
		);
		self::assertTrue($a->equals($b));
		self::assertFalse($a->equals($c));
		self::assertFalse($a->equals(new ObjectType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm')));
	}

	/**
	 * FormShapeType::equals() compares describe(precise()), so FormShape::describe() must render a
	 * replicator's OWN children too - not just its inner row - or two shapes differing only there
	 * (e.g. one branch of a join added a control straight onto the replicator holder, the other
	 * didn't) would describe identically and collapse into one during union dedup, silently dropping
	 * a side's own children. FormReplicatorType::equals() already compares the own shape directly;
	 * this proves the shape layer (FormShape::describe(), which FormShapeType reuses) does too.
	 */
	public function testEqualsIsSensitiveToReplicatorOwnChildren(): void
	{
		$class = 'Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm';
		$inner = FormShape::empty();

		$withoutOwnChild = $this->type(
			$class,
			new FormShape(
				$class,
				[],
				[],
				['rep' => new ReplicatorShape($inner, FormShape::empty())],
				new UnknownInfo(),
				[],
			),
		);
		$withOwnChild = $this->type(
			$class,
			new FormShape(
				$class,
				[],
				[],
				[
					'rep' => new ReplicatorShape(
						$inner,
						new FormShape(
							null,
							['addNode' => new ComponentSlot('addNode', new StringType(), Certainty::HAPPENS, [])],
							[],
							[],
							new UnknownInfo(),
							[],
						),
					),
				],
				new UnknownInfo(),
				[],
			),
		);

		self::assertFalse($withoutOwnChild->equals($withOwnChild));
	}

	public function testHasOffsetValueTypeYesForKnownSlot(): void
	{
		$t = $this->type(
			'Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm',
			$this->shape('Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm'),
		);

		self::assertTrue($t->hasOffsetValueType(new ConstantStringType('a'))->yes());
	}

	/**
	 * componentTypes carries no presence axis (unlike slots/containers/replicators): a name lands
	 * there from ANY joined branch that added it, never gated on every branch having done so - so
	 * unlike a real slot/container/replicator match, it must NOT contribute Yes here (only
	 * classify() treats it as sufficient for staying silent, the safe direction; a false Yes at the
	 * type level actively misinforms core's issetCheck() about nullability, the unsafe direction).
	 */
	public function testHasOffsetValueTypeNotYesForComponentTypesOnlyEntry(): void
	{
		$class = 'Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm';
		$shape = new FormShape(
			$class,
			[],
			[],
			[],
			new UnknownInfo(),
			[],
			[],
			[],
			['save' => CustomSubmitButton::class],
		);
		$t = $this->type($class, $shape);

		$result = $t->hasOffsetValueType(new ConstantStringType('save'));
		self::assertFalse($result->yes());
		self::assertFalse($result->no());
	}

	/**
	 * Mirrors Nette's Container::getComponent() splitting a name on IComponent::NameSeparator and
	 * descending recursively (vendor/nette/component-model/src/ComponentModel/Container.php:116):
	 * $shape['rep-addNode'] is the same lookup as $shape['rep']['addNode'], where 'addNode' is a
	 * control added straight onto the replicator holder rather than one of its rows.
	 */
	public function testHasOffsetValueTypeYesForSeparatorPathIntoReplicatorOwnChild(): void
	{
		$class = 'Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm';
		$own = new FormShape(
			null,
			['addNode' => new ComponentSlot('addNode', new StringType(), Certainty::HAPPENS, [])],
			[],
			[],
			new UnknownInfo(),
			[],
		);
		$rep = new ReplicatorShape(FormShape::empty(), $own);
		$shape = new FormShape($class, [], [], ['rep' => $rep], new UnknownInfo(), []);
		$t = $this->type($class, $shape);

		self::assertTrue($t->hasOffsetValueType(new ConstantStringType('rep-addNode'))->yes());
	}

	/**
	 * A slot/container/replicator whose presence is only Certainty::MAYBE (a conditionally-added
	 * control) must NOT answer Yes either - it might not exist on some path, so a coalesce/isset
	 * against it must stay conservatively nullable. Confirmed live against a real fixture
	 * (Inference/Fixtures/Type/Offset.php g1_20): `if ($c) { $form->addText('a'); } $form['a'] ??
	 * null;` regressed to a non-nullable resolved type when an earlier draft answered Yes here
	 * regardless of presence.
	 */
	public function testHasOffsetValueTypeNotYesForOnlyMaybePresentSlot(): void
	{
		$class = 'Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm';
		$shape = new FormShape(
			$class,
			['a' => new ComponentSlot('a', new StringType(), Certainty::MAYBE, [])],
			[],
			[],
			new UnknownInfo(),
			[],
		);
		$t = $this->type($class, $shape);

		$result = $t->hasOffsetValueType(new ConstantStringType('a'));
		self::assertFalse($result->yes());
		self::assertFalse($result->no());
	}

	/**
	 * Never No is load-bearing: core's NonexistentOffsetInArrayDimFetchCheck reports on a definite
	 * No, which would double-report against FormShapeUnknownAccessRule's own message for the same
	 * unknown access. A closed shape with a genuinely absent name must still degrade to whatever
	 * the wrapped class alone would answer (Maybe here, since Container's ArrayAccess<string,
	 * IComponent> stub accepts any string), never escalate to a confident No.
	 */
	public function testHasOffsetValueTypeNeverNoForUnknownName(): void
	{
		$t = $this->type(
			'Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm',
			$this->shape('Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm'),
		);

		$result = $t->hasOffsetValueType(new ConstantStringType('nope'));
		self::assertFalse($result->no());
	}

}
