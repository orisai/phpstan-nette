<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Unit;

use OriPhpstan\Nette\Forms\Component\FormOriginTracer;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPStan\Parser\Parser;
use Tests\OriPhpstan\Nette\Toolkit\FormShapeTestCase;
use function array_filter;
use function array_values;
use function assert;

final class FormOriginTracerTest extends FormShapeTestCase
{

	public function testLinksHandlerParamToRegisteringFactoryComponent(): void
	{
		$file = __DIR__ . '/Support/HandlerFixture.php';
		$parser = self::getContainer()->getService('defaultAnalysisParser');
		assert($parser instanceof Parser);
		$ast = $parser->parseFile($file);
		$class = (new NodeFinder())->findFirstInstanceOf($ast, Class_::class);
		self::assertNotNull($class);

		$byName = static function (string $n) use ($ast): ClassMethod {
			$ms = array_values(array_filter(
				(new NodeFinder())->findInstanceOf($ast, ClassMethod::class),
				static fn (ClassMethod $m): bool => $m->name->toString() === $n,
			));

			return $ms[0];
		};
		$tracer = new FormOriginTracer();

		self::assertSame('orderForm', $tracer->originComponent($class, $byName('orderSucceeded'), 'form'));
		self::assertNull($tracer->originComponent($class, $byName('unrelated'), 'form'));
	}

	public function testUnifiesSingleCallSiteChainAndDefersOnDivergence(): void
	{
		$file = __DIR__ . '/Support/HelperFixture.php';
		$parser = self::getContainer()->getService('defaultAnalysisParser');
		assert($parser instanceof Parser);
		$ast = $parser->parseFile($file);
		$class = (new NodeFinder())->findFirstInstanceOf($ast, Class_::class);
		self::assertNotNull($class);

		$byName = static function (string $n) use ($ast): ClassMethod {
			$ms = array_values(array_filter(
				(new NodeFinder())->findInstanceOf($ast, ClassMethod::class),
				static fn (ClassMethod $m): bool => $m->name->toString() === $n,
			));

			return $ms[0];
		};
		$tracer = new FormOriginTracer();

		self::assertSame('addrForm|addr', $tracer->originChainKey($class, $byName('fill'), 'c'));
		self::assertNull($tracer->originChainKey($class, $byName('diverge'), 'c'));
	}

}
