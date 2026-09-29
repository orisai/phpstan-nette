<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms;

use OriPhpstan\Nette\Forms\Component\ContainerModel;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Forms\Shape\ComponentSlot;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use OriPhpstan\Nette\Forms\Shape\UnknownInfo;
use OriPhpstan\Nette\Forms\Shape\UnknownReason;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\MixedType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;
use ReflectionClass;
use ReflectionMethod;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;
use function assert;

/**
 * unionOfShapeChildren backs a dynamic single-offset access ($form[$dynamicKey]), which returns
 * one child. It deliberately narrows to the union of the children it can type — skipping an
 * untyped slot and ignoring an open shape rather than falling back to the container's base type.
 * Adding an immediateChildrenUnion-style guard (null on any untyped slot / open shape) was
 * evaluated and rejected: it widens such accesses to IComponent and produces whole-project false
 * positives on correct code that reaches control methods through a dynamic offset
 * ($form[$name]->setItems(...), $form[$inputName]->getControlPart()). These tests lock the
 * precision choice in place.
 */
final class UnionOfShapeChildrenGuardTest extends PHPStanTestCase
{

	use VersionGroupGate;

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		self::getContainer();
	}

	public function testAllTypedSetYieldsUnion(): void
	{
		$type = $this->union($this->shape([
			'a' => $this->typedSlot('a', 'Nette\Forms\Controls\TextInput'),
			'b' => $this->typedSlot('b', 'Nette\Forms\Controls\Checkbox'),
		]));

		self::assertNotNull($type);
		self::assertStringContainsString('Nette\Forms\Controls\TextInput', $type->describe(VerbosityLevel::precise()));
		self::assertStringContainsString('Nette\Forms\Controls\Checkbox', $type->describe(VerbosityLevel::precise()));
	}

	public function testUntypedSlotIsSkippedNotNulled(): void
	{
		$type = $this->union($this->shape([
			'a' => $this->typedSlot('a', 'Nette\Forms\Controls\TextInput'),
			'u' => new ComponentSlot('u', new MixedType(), Certainty::HAPPENS, []),
		]));

		self::assertNotNull($type);
		self::assertSame('Nette\Forms\Controls\TextInput', $type->describe(VerbosityLevel::precise()));
	}

	public function testOpenShapeStillNarrows(): void
	{
		$type = $this->union($this->shape(
			['a' => $this->typedSlot('a', 'Nette\Forms\Controls\TextInput')],
			new UnknownInfo([UnknownReason::DYNAMIC_NAME]),
		));

		self::assertNotNull($type);
		self::assertSame('Nette\Forms\Controls\TextInput', $type->describe(VerbosityLevel::precise()));
	}

	private function union(FormShape $shape): ?Type
	{
		$model = (new ReflectionClass(ContainerModel::class))->newInstanceWithoutConstructor();
		$method = new ReflectionMethod(ContainerModel::class, 'unionOfShapeChildren');
		$method->setAccessible(true);

		$result = $method->invoke($model, $shape);
		assert($result instanceof Type || $result === null);

		return $result;
	}

	/**
	 * @param array<string, ComponentSlot> $slots
	 */
	private function shape(array $slots, ?UnknownInfo $unknown = null): FormShape
	{
		return new FormShape(
			'Nette\Forms\Container',
			$slots,
			[],
			[],
			$unknown ?? new UnknownInfo(),
			[],
		);
	}

	private function typedSlot(string $name, string $controlClass): ComponentSlot
	{
		return new ComponentSlot($name, new StringType(), Certainty::HAPPENS, [], $controlClass);
	}

}
