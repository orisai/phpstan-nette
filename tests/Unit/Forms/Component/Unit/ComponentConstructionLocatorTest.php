<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Unit;

use OriPhpstan\Nette\Forms\Component\ComponentConstructionLocator;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPStan\Parser\Parser;
use Tests\OriPhpstan\Nette\Toolkit\FormShapeTestCase;
use function assert;

final class ComponentConstructionLocatorTest extends FormShapeTestCase
{

	public function testLocatesCreateComponentFactoryByComponentName(): void
	{
		$file = __DIR__ . '/Support/LocatorFixture.php';
		$parser = self::getContainer()->getService('defaultAnalysisParser');
		assert($parser instanceof Parser);
		$ast = $parser->parseFile($file);
		$class = (new NodeFinder())->findFirstInstanceOf($ast, Class_::class);
		self::assertNotNull($class);

		$locator = new ComponentConstructionLocator();

		$found = $locator->locate($class, 'signInForm');
		self::assertInstanceOf(ClassMethod::class, $found);
		self::assertSame('createComponentSignInForm', $found->name->toString());

		self::assertNull($locator->locate($class, 'doesNotExist'));
	}

}
