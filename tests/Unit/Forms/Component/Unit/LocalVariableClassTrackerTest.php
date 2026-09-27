<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Unit;

use OriPhpstan\Nette\Forms\Analyzer\LocalVariableClassTracker;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPStan\Parser\Parser;
use Tests\OriPhpstan\Nette\Toolkit\FormShapeTestCase;
use function assert;

final class LocalVariableClassTrackerTest extends FormShapeTestCase
{

	private const OWNER = 'Tests\OriPhpstan\Nette\Unit\Forms\Component\Unit\Support\ClassTrackerFixture';

	private const FORM = 'Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm';

	/**
	 * @dataProvider provideMethods
	 */
	public function testResolvesEntryClassScopeFree(string $method, ?string $expected): void
	{
		$tracker = new LocalVariableClassTracker(self::createReflectionProvider());

		self::assertSame($expected, $tracker->resolveContainerClass('form', $this->method($method), self::OWNER));
	}

	/** @return iterable<string, array{string, string|null}> */
	public static function provideMethods(): iterable
	{
		yield 'new in body' => ['newForm', self::FORM];
		yield 'factory create() return type' => ['factoryForm', self::FORM];
		yield 'self method declared return type' => ['selfMethodForm', 'Nette\\Forms\\Form'];
		yield 'alias of factory result' => ['aliasForm', self::FORM];
		yield 'typed parameter' => ['paramForm', self::FORM];
		yield 'ambiguous assignments bail' => ['untracked', null];
		yield 'inner closure reassign is ignored' => ['closureShadow', self::FORM];
	}

	public function testResolvesViaInheritedPrivateFactoryProperty(): void
	{
		$tracker = new LocalVariableClassTracker(self::createReflectionProvider());
		$owner = 'Tests\OriPhpstan\Nette\Unit\Forms\Component\Unit\Support\ClassTrackerChildControl';

		$method = $this->methodOf(
			__DIR__ . '/Support/ClassTrackerChildControl.php',
			'createComponentForm',
		);

		self::assertSame(self::FORM, $tracker->resolveContainerClass('form', $method, $owner));
	}

	public function testResolvesThisToContainerOwner(): void
	{
		$tracker = new LocalVariableClassTracker(self::createReflectionProvider());
		$owner = 'Tests\OriPhpstan\Nette\Unit\Forms\Component\Unit\Support\ClassTrackerForm';

		self::assertSame($owner, $tracker->resolveContainerClass('this', $this->method('newForm'), $owner));
	}

	public function testNullOwnerStillResolvesNew(): void
	{
		$tracker = new LocalVariableClassTracker(self::createReflectionProvider());

		self::assertSame(self::FORM, $tracker->resolveContainerClass('form', $this->method('newForm'), null));
	}

	public function testNullOwnerCannotResolveFactory(): void
	{
		$tracker = new LocalVariableClassTracker(self::createReflectionProvider());

		self::assertNull($tracker->resolveContainerClass('form', $this->method('factoryForm'), null));
	}

	private function method(string $name): ClassMethod
	{
		return $this->methodOf(__DIR__ . '/Support/ClassTrackerFixture.php', $name);
	}

	private function methodOf(string $file, string $name): ClassMethod
	{
		$parser = self::getContainer()->getService('defaultAnalysisParser');
		assert($parser instanceof Parser);
		$ast = $parser->parseFile($file);
		$class = (new NodeFinder())->findFirstInstanceOf($ast, Class_::class);
		assert($class !== null);

		foreach ($class->getMethods() as $methodNode) {
			if ($methodNode->name->toString() === $name) {
				return $methodNode;
			}
		}

		self::fail("Method {$name} not found");
	}

}
