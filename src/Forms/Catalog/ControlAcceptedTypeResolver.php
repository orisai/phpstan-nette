<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Catalog;

use BackedEnum;
use DateTimeInterface;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Type\BooleanType;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\IterableType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use Stringable;
use function strlen;
use function strpos;
use function substr;

final class ControlAcceptedTypeResolver
{

	public const CUSTOM_SPEC_PREFIX = 'type:';

	private ?TypeStringResolver $typeStringResolver;

	public function __construct(?TypeStringResolver $typeStringResolver = null)
	{
		$this->typeStringResolver = $typeStringResolver;
	}

	public function isNoOp(string $spec): bool
	{
		return $spec === 'upload';
	}

	public function acceptedLabel(string $spec): string
	{
		$custom = $this->customType($spec);
		if ($custom !== null) {
			return $custom;
		}

		switch ($spec) {
			case 'text':
			case 'integer':
			case 'float':
				return 'scalar|Stringable|null';
			case 'checkbox':
				return 'scalar|null';
			case 'choice':
				return 'string|int|BackedEnum|null';
			case 'multichoice':
				return 'iterable<scalar|Stringable|BackedEnum>|scalar|null';
			case 'hidden':
				return 'scalar|Stringable|BackedEnum|null';
			case 'datetime':
				return 'DateTimeInterface|string|int|null';
			case 'color':
				return 'string|null';
			default:
				return 'mixed';
		}
	}

	public function accepted(string $spec, bool $nullable): Type
	{
		$custom = $this->customType($spec);
		if ($custom !== null) {
			return $this->typeStringResolver !== null
				? $this->typeStringResolver->resolve($custom)
				: new MixedType();
		}

		switch ($spec) {
			case 'text':
			case 'integer':
			case 'float':
				return TypeCombinator::union(
					new IntegerType(),
					new FloatType(),
					new StringType(),
					new BooleanType(),
					new ObjectType(Stringable::class),
					new NullType(),
				);
			case 'checkbox':
				return TypeCombinator::union(
					new IntegerType(),
					new FloatType(),
					new StringType(),
					new BooleanType(),
					new NullType(),
				);
			case 'choice':
				return TypeCombinator::union(
					new StringType(),
					new IntegerType(),
					// @phpstan-ignore class.notFound (BackedEnum is PHP 8.1+; ::class needs no autoload)
					new ObjectType(BackedEnum::class),
					new NullType(),
				);
			case 'multichoice':
				return TypeCombinator::union(
					new IterableType(
						new MixedType(true),
						TypeCombinator::union(
							new StringType(),
							new IntegerType(),
							new BooleanType(),
							new FloatType(),
							new ObjectType(Stringable::class),
							// @phpstan-ignore class.notFound (BackedEnum is PHP 8.1+; ::class needs no autoload)
							new ObjectType(BackedEnum::class),
						),
					),
					new IntegerType(),
					new FloatType(),
					new StringType(),
					new BooleanType(),
					new NullType(),
				);
			case 'hidden':
				return TypeCombinator::union(
					new IntegerType(),
					new FloatType(),
					new StringType(),
					new BooleanType(),
					new ObjectType(Stringable::class),
					// @phpstan-ignore class.notFound (BackedEnum is PHP 8.1+; ::class needs no autoload)
					new ObjectType(BackedEnum::class),
					new NullType(),
				);
			case 'upload':
				return new MixedType();
			case 'datetime':
				return TypeCombinator::union(
					new ObjectType(DateTimeInterface::class),
					new StringType(),
					new IntegerType(),
					new NullType(),
				);
			case 'color':
				return TypeCombinator::union(
					new StringType(),
					new NullType(),
				);
			default:
				return new MixedType();
		}
	}

	private function customType(string $spec): ?string
	{
		return strpos($spec, self::CUSTOM_SPEC_PREFIX) === 0
			? (string) substr($spec, strlen(self::CUSTOM_SPEC_PREFIX))
			: null;
	}

}
