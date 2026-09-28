<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Declarations;

use OriPhpstan\Nette\Latte\Declarations\CommonClassAncestor;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\AncestorContract;
use Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\AncestorLeft;
use Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\AncestorMiddle;
use Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\AncestorRight;
use Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\AncestorRoot;
use Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\AncestorUnrelated;

// The widening the multi-renderer merge folds with. Any common supertype is SOUND (it can only
// miss findings, never invent them); the deepest one is the tightest such class.
final class CommonClassAncestorTest extends BaseTestCase
{

	public function testEqualTypesAreReturnedVerbatim(): void
	{
		self::assertSame('array<stdClass>', CommonClassAncestor::of('array<stdClass>', 'array<stdClass>'));
	}

	public function testSiblingsWidenToTheirSharedParent(): void
	{
		self::assertSame(
			'\\' . AncestorMiddle::class,
			CommonClassAncestor::of('\\' . AncestorLeft::class, '\\' . AncestorRight::class),
		);
	}

	public function testSubclassAndAncestorWidenToTheAncestor(): void
	{
		self::assertSame(
			'\\' . AncestorRoot::class,
			CommonClassAncestor::of('\\' . AncestorLeft::class, '\\' . AncestorRoot::class),
		);
	}

	// Interfaces are excluded by design: class_exists() answers false for an interface, so this is
	// the guard's real job - without it, is_a() alone would let a shared interface stand in as a
	// common ancestor.
	public function testSharedInterfaceIsNotACommonAncestor(): void
	{
		self::assertSame(
			'mixed',
			CommonClassAncestor::of('\\' . AncestorContract::class, '\\' . AncestorLeft::class),
		);
		self::assertSame(
			'mixed',
			CommonClassAncestor::of('\\' . AncestorLeft::class, '\\' . AncestorContract::class),
		);
	}

	// chain()'s first element is the raw input, so when $a is itself the answer, an un-ltrimmed
	// leading backslash on $a would double up in the returned string.
	public function testAncestorPassedFirstIsNotDoubleBackslashed(): void
	{
		self::assertSame(
			'\\' . AncestorRoot::class,
			CommonClassAncestor::of('\\' . AncestorRoot::class, '\\' . AncestorLeft::class),
		);
	}

	// The raw @var strings PropertyTypeResolver hands back carry no leading backslash; both spellings
	// must reach the same class, because one side of a merge is often a declaration and the other a
	// refined FQCN.
	public function testLeadingBackslashIsNotSignificantOnInput(): void
	{
		self::assertSame(
			'\\' . AncestorMiddle::class,
			CommonClassAncestor::of(AncestorLeft::class, '\\' . AncestorRight::class),
		);
	}

	public function testUnrelatedClassesHaveNoCommonAncestor(): void
	{
		self::assertSame(
			'mixed',
			CommonClassAncestor::of('\\' . AncestorLeft::class, '\\' . AncestorUnrelated::class),
		);
	}

	// Array shapes and scalars are not classes - the merge's pre-existing answer for them stands.
	public function testNonClassTypesHaveNoCommonAncestor(): void
	{
		self::assertSame('mixed', CommonClassAncestor::of('\stdClass[]', 'array<stdClass>'));
		self::assertSame('mixed', CommonClassAncestor::of('string', 'int'));
	}

	public function testMixedAbsorbs(): void
	{
		self::assertSame('mixed', CommonClassAncestor::of('mixed', '\\' . AncestorLeft::class));
		self::assertSame('mixed', CommonClassAncestor::of('\\' . AncestorLeft::class, 'mixed'));
	}

	public function testArgumentOrderDoesNotMatter(): void
	{
		self::assertSame(
			CommonClassAncestor::of('\\' . AncestorLeft::class, '\\' . AncestorRight::class),
			CommonClassAncestor::of('\\' . AncestorRight::class, '\\' . AncestorLeft::class),
		);
	}

	// The fold must be associative, because intersect() reduces the renderer set pairwise and the
	// injected declaration feeds EdgeFingerprint - an order-dependent answer would make the cache
	// non-deterministic.
	public function testFoldIsAssociative(): void
	{
		$left = '\\' . AncestorLeft::class;
		$right = '\\' . AncestorRight::class;
		$root = '\\' . AncestorRoot::class;

		self::assertSame(
			CommonClassAncestor::of(CommonClassAncestor::of($left, $right), $root),
			CommonClassAncestor::of($left, CommonClassAncestor::of($right, $root)),
		);
	}

}
