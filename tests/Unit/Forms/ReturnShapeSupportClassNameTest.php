<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms;

use OriPhpstan\Nette\Forms\Component\ReturnShapeSupport;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;

/**
 * The read-back half of the two class-name sentinels FormShapeAnalyzer writes. Neither spelling
 * names a loadable class, so every consumer of this predicate must degrade rather than wrap them in
 * an object type; a real single class must never be swept along with them.
 *
 * Driven through a provider rather than literal arguments on purpose: this project analyses its own
 * tests, and a pure static predicate called with a constant folds to a constant, which turns every
 * assertion here into an always-true/always-false finding.
 */
final class ReturnShapeSupportClassNameTest extends BaseTestCase
{

	/**
	 * @return iterable<string, array{string|null, bool}>
	 */
	public static function classNameProvider(): iterable
	{
		yield 'null' => [null, true];
		yield 'mixed sentinel' => ['mixed', true];
		yield 'leading-slash mixed sentinel' => ['\\mixed', true];
		yield 'pipe-joined pseudo-class' => ['Foo\\Bar|Foo\\Baz', true];
		yield 'leading-slash pipe-joined pseudo-class' => ['\\Foo\\Bar|\\Foo\\Baz', true];
		yield 'single concrete class' => ['Foo\\Bar', false];
		yield 'leading-slash single concrete class' => ['\\Foo\\Bar', false];
		yield 'class whose name merely contains mixed' => ['Foo\\MixedThing', false];
	}

	/** @dataProvider classNameProvider */
	public function testIsClassNameUnresolved(?string $className, bool $unresolved): void
	{
		self::assertSame($unresolved, ReturnShapeSupport::isClassNameUnresolved($className));
	}

}
