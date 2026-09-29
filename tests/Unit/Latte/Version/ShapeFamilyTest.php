<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Version;

use InvalidArgumentException;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;

final class ShapeFamilyTest extends BaseTestCase
{

	/**
	 * @dataProvider provideDetect
	 */
	public function testDetect(string $latteVersion, ?string $formsVersion, string $expectedId): void
	{
		$family = ShapeFamily::detect($latteVersion, $formsVersion);

		self::assertSame($expectedId, $family->id());
		self::assertSame($expectedId, $family->latteLine . '/' . $family->formsBridge);
	}

	/**
	 * @return iterable<string, array{string, string|null, string}>
	 */
	public function provideDetect(): iterable
	{
		yield 'latte 2 with forms 3.1' => ['2.11.7', '3.1.15', '2/macros'];
		yield 'latte 2 with forms 3.3 stays on macros' => ['2.11.7', '3.3.0', '2/macros'];
		yield 'latte 2 without forms' => ['2.11.6', null, '2/macros'];
		yield 'latte 2 composer-normalized' => ['2.11.7.0', '3.1.15.0', '2/macros'];
		yield 'latte 3.0 with forms 3.2' => ['3.0.26', '3.2.9', '3.0/item'];
		yield 'latte 3.0 with forms 3.1.7' => ['3.0.26', '3.1.7', '3.0/item'];
		yield 'latte 3.0 without forms' => ['3.0.26', null, '3.0/item'];
		yield 'latte 3.1 with forms 3.3' => ['3.1.6', '3.3.0', '3.1/provider'];
		yield 'latte 3.1 with forms 3.2' => ['3.1.6', '3.2.9', '3.1/item'];
		yield 'latte 3.1 without forms' => ['3.1.6', null, '3.1/item'];
		yield 'latte 3.1 composer-normalized' => ['3.1.6.0', '3.3.0.0', '3.1/provider'];
		yield 'latte 3.1 dev branch alias' => ['3.1.x-dev', '3.3.x-dev', '3.1/provider'];
		yield 'v-prefixed' => ['v3.0.26', 'v3.3.0', '3.0/provider'];
	}

	/**
	 * @dataProvider provideUnsupported
	 */
	public function testUnsupportedLatteVersionIsRejected(string $latteVersion): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unsupported latte/latte version "' . $latteVersion . '".');

		ShapeFamily::detect($latteVersion, null);
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public function provideUnsupported(): iterable
	{
		yield 'latte 3.2' => ['3.2.0'];
		yield 'latte 4' => ['4.0.0'];
		yield 'latte 1' => ['1.9.0'];
		yield 'branch without a number' => ['dev-master'];
	}

	public function testIdJoinsTheLineAndTheBridge(): void
	{
		$family = new ShapeFamily(ShapeFamily::LATTE_31, ShapeFamily::FORMS_PROVIDER);

		self::assertSame('3.1/provider', $family->id());
		self::assertSame(ShapeFamily::LATTE_31, $family->latteLine);
		self::assertSame(ShapeFamily::FORMS_PROVIDER, $family->formsBridge);
	}

}
