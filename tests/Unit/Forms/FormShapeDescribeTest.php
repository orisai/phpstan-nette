<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms;

use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Forms\Shape\ComponentSlot;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use OriPhpstan\Nette\Forms\Shape\ReplicatorShape;
use OriPhpstan\Nette\Forms\Shape\UnknownInfo;
use OriPhpstan\Nette\Forms\Shape\UnknownReason;
use PHPStan\Type\IntegerType;
use PHPStan\Type\NullType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;

final class FormShapeDescribeTest extends BaseTestCase
{

	private function slot(string $name, Type $type, string $presence, bool $typeOpaque = false): ComponentSlot
	{
		return new ComponentSlot($name, $type, $presence, [], null, false, null, false, $typeOpaque);
	}

	public function testFlatPresentAndMaybeAndUnknownType(): void
	{
		$shape = new FormShape(
			'Form',
			[
				'a' => $this->slot('a', new StringType(), Certainty::HAPPENS),
				'b' => $this->slot('b', TypeCombinator::union(new IntegerType(), new NullType()), Certainty::MAYBE),
				'c' => $this->slot('c', new StringType(), Certainty::HAPPENS, true),
			],
			[],
			[],
			new UnknownInfo(),
			[],
		);
		self::assertSame('{a: string, b?: int|null, c: *UNKNOWN*}', $shape->describe(VerbosityLevel::precise()));
	}

	public function testRemainderSuffixSortedReasons(): void
	{
		$shape = new FormShape(
			'Form',
			['a' => $this->slot('a', new StringType(), Certainty::HAPPENS)],
			[],
			[],
			(new UnknownInfo())->withReason(UnknownReason::EXTENSION_METHOD)->withReason(UnknownReason::DYNAMIC_NAME),
			[],
		);
		$d = $shape->describe(VerbosityLevel::precise());
		self::assertStringEndsWith('{a: string, …+unknown(dynamic_name,extension_method)}', $d);
	}

	public function testNestedContainerAndReplicator(): void
	{
		$inner = new FormShape(
			'Form',
			['x' => $this->slot('x', new StringType(), Certainty::HAPPENS)],
			[],
			[],
			new UnknownInfo(),
			[],
		);
		$shape = new FormShape(
			'Form',
			[],
			['c' => $inner],
			['d' => new ReplicatorShape($inner, FormShape::empty())],
			new UnknownInfo(),
			[],
		);
		$d = $shape->describe(VerbosityLevel::precise());
		self::assertStringEndsWith('{c: Form{x: string}, d: array<int, Form{x: string}>}', $d);
	}

	/**
	 * A replicator's own children (a control added straight onto the addDynamic() return value, not
	 * inside the item-factory closure) must show up in describe() - FormShapeType::equals() compares
	 * this string, so an own child silently missing here would make two shapes differing only in
	 * their own children compare equal (see FormShapeTypeTest::
	 * testEqualsIsSensitiveToReplicatorOwnChildren for the equals()-level consequence).
	 */
	public function testReplicatorOwnChildrenIncluded(): void
	{
		$inner = new FormShape(
			'Form',
			['x' => $this->slot('x', new StringType(), Certainty::HAPPENS)],
			[],
			[],
			new UnknownInfo(),
			[],
		);
		$own = new FormShape(
			null,
			['addNode' => $this->slot('addNode', new StringType(), Certainty::HAPPENS)],
			[],
			[],
			new UnknownInfo(),
			[],
		);
		$shape = new FormShape(
			'Form',
			[],
			[],
			['d' => new ReplicatorShape($inner, $own)],
			new UnknownInfo(),
			[],
		);
		$d = $shape->describe(VerbosityLevel::precise());
		self::assertStringEndsWith('{d: array<int, Form{x: string}>+own{addNode: string}}', $d);
	}

}
