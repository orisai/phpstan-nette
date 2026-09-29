<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Unit;

use OriPhpstan\Nette\Forms\Catalog\ControlAcceptedTypeResolver;
use PHPStan\Testing\TypeInferenceTestCase;
use PHPStan\Type\BooleanType;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;

final class AcceptedTypeCatalogTest extends TypeInferenceTestCase
{

	use VersionGroupGate;

	private ControlAcceptedTypeResolver $resolver;

	protected function setUp(): void
	{
		parent::setUp();
		self::createReflectionProvider();
		$this->resolver = new ControlAcceptedTypeResolver();
	}

	public function testAcceptedLabelMatchesGatedCuratedStrings(): void
	{
		self::assertSame('scalar|Stringable|null', $this->resolver->acceptedLabel('text'));
		self::assertSame('scalar|Stringable|null', $this->resolver->acceptedLabel('integer'));
		self::assertSame('scalar|Stringable|null', $this->resolver->acceptedLabel('float'));
		self::assertSame('scalar|null', $this->resolver->acceptedLabel('checkbox'));
		self::assertSame('string|int|BackedEnum|null', $this->resolver->acceptedLabel('choice'));
		self::assertSame(
			'iterable<scalar|Stringable|BackedEnum>|scalar|null',
			$this->resolver->acceptedLabel('multichoice'),
		);
		self::assertSame('scalar|Stringable|BackedEnum|null', $this->resolver->acceptedLabel('hidden'));
		self::assertSame('DateTimeInterface|string|int|null', $this->resolver->acceptedLabel('datetime'));
		self::assertSame('string|null', $this->resolver->acceptedLabel('color'));
		self::assertSame('mixed', $this->resolver->acceptedLabel('upload'));
		self::assertSame('mixed', $this->resolver->acceptedLabel('xxx'));
		self::assertSame('mixed', $this->resolver->acceptedLabel(''));
	}

	public function testIsNoOpOnlyForUpload(): void
	{
		self::assertTrue($this->resolver->isNoOp('upload'));
		foreach (['text', 'integer', 'float', 'checkbox', 'choice', 'multichoice', 'hidden', 'datetime', 'color', 'xxx', ''] as $spec) {
			self::assertFalse($this->resolver->isNoOp($spec), $spec);
		}
	}

	public function testAcceptanceSemanticsAlignWithGatedRows(): void
	{
		self::assertTrue($this->accepts('text', new StringType()));
		self::assertTrue($this->accepts('text', new IntegerType()));
		self::assertTrue($this->accepts('text', new NullType()));
		self::assertFalse($this->accepts('text', new ObjectType('stdClass')));

		self::assertTrue($this->accepts('checkbox', new BooleanType()));
		self::assertTrue($this->accepts('checkbox', new StringType()));
		self::assertFalse($this->accepts('checkbox', new ObjectType('stdClass')));

		self::assertTrue($this->accepts('choice', new StringType()));
		self::assertTrue($this->accepts('choice', new IntegerType()));
		self::assertTrue($this->accepts('choice', new ObjectType('BackedEnum')));
		self::assertTrue($this->accepts('choice', new NullType()));
		self::assertFalse($this->accepts('choice', new FloatType()));

		self::assertFalse($this->accepts('multichoice', new ObjectType('stdClass')));

		self::assertTrue($this->accepts('hidden', new StringType()));

		self::assertTrue($this->accepts('datetime', new ObjectType('DateTime')));
		self::assertTrue($this->accepts('datetime', new ObjectType('DateTimeImmutable')));
		self::assertTrue($this->accepts('datetime', new StringType()));
		self::assertTrue($this->accepts('datetime', new IntegerType()));
		self::assertTrue($this->accepts('datetime', new NullType()));
		self::assertFalse($this->accepts('datetime', new BooleanType()));

		self::assertTrue($this->accepts('color', new StringType()));
		self::assertTrue($this->accepts('color', new NullType()));
		self::assertFalse($this->accepts('color', new IntegerType()));
	}

	public function testNullableDoesNotChangeAcceptedSet(): void
	{
		foreach (['text', 'integer', 'float', 'checkbox', 'choice', 'multichoice', 'hidden', 'datetime', 'color', 'upload', 'xxx', ''] as $spec) {
			self::assertSame(
				$this->resolver->accepted($spec, false)->describe(VerbosityLevel::precise()),
				$this->resolver->accepted($spec, true)->describe(VerbosityLevel::precise()),
				$spec,
			);
		}
	}

	public function testAcceptedSetIsNotTheGetType(): void
	{
		self::assertNotSame('DateTimeImmutable|null', $this->resolver->acceptedLabel('datetime'));
		self::assertNotSame('bool', $this->resolver->acceptedLabel('checkbox'));
		self::assertNotSame('int|null', $this->resolver->acceptedLabel('integer'));
	}

	private function accepts(string $spec, Type $given): bool
	{
		return $this->resolver->accepted($spec, false)->accepts($given, true)->yes();
	}

}
