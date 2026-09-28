<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Declarations;

use OriPhpstan\Nette\Latte\Declarations\PropertyTypeResolver;
use ReflectionClass;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\DeclaredSurfaceFixture;
use Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\TemplateParamPropertyFixture;
use Tests\OriPhpstan\Nette\Unit\Latte\Fixtures\Support\FixtureTemplate;

final class PropertyTypeResolverTest extends BaseTestCase
{

	// UITemplate's `@template C of Control` + `/** @var C */ public Control $control` leaks the bare
	// type-parameter letter as if it were a real class name (class.notFound "C") once a concrete
	// template binds it via @extends - resolving that binding is out of scope, but the leak itself
	// must not surface.
	public function testTemplateParameterPropertyResolvesAsMixed(): void
	{
		$reflection = new ReflectionClass(TemplateParamPropertyFixture::class);

		self::assertSame('mixed', PropertyTypeResolver::resolve($reflection->getProperty('control')));
	}

	public function testNonTemplateParameterPropertyIsUnaffected(): void
	{
		$reflection = new ReflectionClass(TemplateParamPropertyFixture::class);

		self::assertSame('string', PropertyTypeResolver::resolve($reflection->getProperty('title')));
	}

	public function testDocblockVarTypeStillResolvesWhenClassHasNoTemplateTag(): void
	{
		$reflection = new ReflectionClass(FixtureTemplate::class);

		self::assertSame('array<string>', PropertyTypeResolver::resolve($reflection->getProperty('tags')));
	}

	// The declared surface is deliberately unfiltered by initialization: a typed property with no
	// default (absent from getDefaultProperties(), i.e. uninitialized until something writes it) is
	// a declared parameter exactly like a defaulted one, inherited ones included. Declaring is not
	// inferring - FactoryProvidedVars::readTemplateClass() answers the opposite question (what does
	// the factory actually write) and therefore does apply that gate. Only visibility filters here.
	// See docs/phpstan-latte.md's "A declared property is a parameter even when nothing ever writes
	// it" limitation for the runtime gap this buys.
	public function testDeclaredSurfaceKeepsTypedPropertiesWithNoDefaultAndDropsOnlyNonPublicOnes(): void
	{
		self::assertSame(
			[
				'typedNoDefault' => 'int',
				'defaulted' => 'string',
				'docblockTyped' => 'string',
				'inheritedNoDefault' => 'bool',
			],
			PropertyTypeResolver::resolveAllPublic(DeclaredSurfaceFixture::class),
		);
	}

}
