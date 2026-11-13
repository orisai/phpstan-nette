<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Declarations;

use OriPhpstan\Nette\Latte\Declarations\PropertyTypeResolver;
use ReflectionClass;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\DeclaredSurfaceFixture;
use Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\NameScope\Base\BaseOnly;
use Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\NameScope\Imported\ImportedOption;
use Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\NameScope\NameScopeTemplate;
use Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\NameScope\SiblingOption;
use Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\NameScope\Traits\TraitOnly;
use Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\TemplateBinding\BoundControlBase;
use Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\TemplateBinding\Leaf\BoundTemplateLeaf;
use Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\TemplateBinding\Leaf\LeafControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\TemplateBinding\UnboundTemplateLeaf;
use Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\TemplateParamPropertyFixture;
use Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\TemplateParamPropertyFixtureBound;
use Tests\OriPhpstan\Nette\Unit\Latte\Fixtures\Support\FixtureTemplate;
use function ksort;

final class PropertyTypeResolverTest extends BaseTestCase
{

	// UITemplate's `@template C of Control` + `/** @var C */ public Control $control`: a parameter nothing binds stands
	// for its bound, never for a class named after the letter.
	public function testUnboundTemplateParameterPropertyResolvesToItsBound(): void
	{
		$reflection = new ReflectionClass(TemplateParamPropertyFixture::class);

		self::assertSame(
			'\\' . TemplateParamPropertyFixtureBound::class,
			PropertyTypeResolver::resolve($reflection->getProperty('control')),
		);
	}

	public function testTemplateParametersResolveThroughTheExtendsChainOfTheReadClass(): void
	{
		$leaf = '\\' . LeafControl::class;

		self::assertSame(
			[
				'control' => $leaf,
				'controls' => "list<$leaf>",
				'item' => 'string|null',
			],
			PropertyTypeResolver::resolveAllPublic(BoundTemplateLeaf::class),
		);
	}

	public function testTemplateParametersNoExtendsBindResolveToTheirBounds(): void
	{
		$bound = '\\' . BoundControlBase::class;

		self::assertSame(
			[
				'control' => $bound,
				'controls' => "list<$bound>",
				'item' => 'mixed|null',
			],
			PropertyTypeResolver::resolveAllPublic(UnboundTemplateLeaf::class),
		);
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

	// The generated template class lives in the global namespace, so a docblock type must arrive
	// there fully qualified against the file that declares the property - the template class's own,
	// a parent's or a trait's.
	public function testDocblockTypesResolveAgainstTheDeclaringFilesNameScope(): void
	{
		$sibling = '\\' . SiblingOption::class;
		$imported = '\\' . ImportedOption::class;
		$template = '\\' . NameScopeTemplate::class;
		$expected = [
			'siblings' => "list<$sibling>",
			'imported' => "array<int, $imported>",
			'nested' => "array<string, list<$imported>>",
			'shape' => "array{first: $sibling, second?: $imported|null, $template::ModeA: int}",
			'nullableUnion' => "$sibling|null",
			'nullable' => "?$imported",
			'mode' => "$template::Mode*",
			'selfMode' => "$template::Mode*",
			'partiallyQualified' => $imported,
			'fullyQualified' => $sibling,
			'range' => 'int<0, max>',
			'keyword' => 'non-empty-string',
			'templated' => 'list<mixed>',
			'classString' => "class-string<$sibling>",
			'described' => "array<int, $sibling>",
			'native' => SiblingOption::class . '|null',
			'fromTrait' => 'list<\\' . TraitOnly::class . '>',
			'inherited' => "list<$imported>",
			'inheritedSibling' => '\\' . BaseOnly::class . '|null',
		];
		$actual = PropertyTypeResolver::resolveAllPublic(NameScopeTemplate::class);
		ksort($expected);
		ksort($actual);

		self::assertSame($expected, $actual);
	}

}
