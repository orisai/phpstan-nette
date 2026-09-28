<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Metadata;

use OriPhpstan\Nette\Dic\Metadata\ParameterTypeWidener;
use PHPStan\Type\VerbosityLevel;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;

final class ParameterTypeWidenerTest extends BaseTestCase
{

	/**
	 * @return iterable<mixed>
	 */
	public function dataWiden(): iterable
	{
		yield ['secret', 'string'];
		yield [42, 'int'];
		yield [3.14, 'float'];
		yield [true, 'bool'];
		yield [null, 'null'];
		yield [[], 'array'];
		yield [[1, 2], 'list<int>'];
		yield [[1, 'a'], 'list<int|string>'];
		yield [['k' => 'v', 'n' => 1], 'array{k: string, n: int}'];
	}

	/**
	 * @param mixed $value
	 *
	 * @dataProvider dataWiden
	 */
	public function testWiden($value, string $expected): void
	{
		self::assertSame($expected, (new ParameterTypeWidener())->widen($value)->describe(VerbosityLevel::precise()));
	}

	public function testNoConstantValueLeaks(): void
	{
		$type = (new ParameterTypeWidener())->widen(['password' => 'hunter2']);
		self::assertStringNotContainsString('hunter2', $type->describe(VerbosityLevel::precise()));
	}

}
