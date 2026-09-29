<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Postprocess;

use OriPhpstan\Nette\Latte\Compile\LatteCompiler;
use OriPhpstan\Nette\Latte\Compile\SliceClassName;
use OriPhpstan\Nette\Latte\Compile\TemplateClassName;
use OriPhpstan\Nette\Latte\Declarations\DeclarationScanner;
use OriPhpstan\Nette\Latte\Postprocess\DependencyEdgeEmitter;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Nop;
use PhpParser\PrettyPrinter\Standard;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\PipelineFactory;
use function strpos;

final class DependencyEdgeEmitterTest extends BaseTestCase
{

	public function testEmptyNeighborsReturnsStmtsUnchanged(): void
	{
		$stmts = [new Nop()];

		$result = (new DependencyEdgeEmitter())->emit($stmts, []);

		self::assertSame($stmts, $result);
	}

	public function testNoMainMethodReturnsStmtsUnchanged(): void
	{
		$stmts = [new Nop()];

		$result = (new DependencyEdgeEmitter())->emit($stmts, ['templates/foo.latte']);

		self::assertSame($stmts, $result);
	}

	public function testSingleNeighborPrependsAnalyzedCallToMain(): void
	{
		$existing = new Nop();
		$main = new ClassMethod('latteMain', ['stmts' => [$existing]]);
		$class = new Class_('LatteTpl_probe', ['stmts' => [$main]]);

		$result = (new DependencyEdgeEmitter())->emit([$class], ['templates/foo.latte']);

		/** @var Class_ $resultClass */
		$resultClass = $result[0];
		/** @var ClassMethod $resultMain */
		$resultMain = $resultClass->stmts[0];
		self::assertNotNull($resultMain->stmts);
		self::assertCount(2, $resultMain->stmts);
		self::assertSame($existing, $resultMain->stmts[1], 'original body statements are preserved after the edge');

		$printed = (new Standard())->prettyPrint([$resultMain->stmts[0]]);
		self::assertSame(
			'\OriPhpstan\Nette\Latte\Runtime\Helpers::analyzed(\\' . TemplateClassName::forPath('templates/foo.latte')
			. '::class);',
			$printed,
		);
	}

	public function testMultipleNeighborsSortedByClassNameAndDeduped(): void
	{
		$main = new ClassMethod('latteMain', ['stmts' => []]);
		$class = new Class_('LatteTpl_probe', ['stmts' => [$main]]);

		$result = (new DependencyEdgeEmitter())->emit(
			[$class],
			['templates/foo.latte', 'templates/bar.latte', 'templates/foo.latte'],
		);

		/** @var Class_ $resultClass */
		$resultClass = $result[0];
		/** @var ClassMethod $resultMain */
		$resultMain = $resultClass->stmts[0];
		self::assertNotNull($resultMain->stmts);
		self::assertCount(2, $resultMain->stmts, 'duplicate neighbor rel path must be deduped to one call');

		$printed = (new Standard())->prettyPrint($resultMain->stmts);
		$barClass = TemplateClassName::forPath('templates/bar.latte');
		$fooClass = TemplateClassName::forPath('templates/foo.latte');

		$posBar = self::assertStrPos($printed, $barClass);
		$posFoo = self::assertStrPos($printed, $fooClass);
		self::assertTrue($posBar < $posFoo, 'neighbors must be emitted sorted by class name');
	}

	public function testEdgeLandsInFirstContextCloneWhenPlainMainWasReplaced(): void
	{
		$ctx0 = new ClassMethod('latteMain_ctx0', ['stmts' => []]);
		$ctx1 = new ClassMethod('latteMain_ctx1', ['stmts' => []]);
		$class = new Class_('LatteTpl_probe', ['stmts' => [$ctx0, $ctx1]]);

		$result = (new DependencyEdgeEmitter())->emit([$class], ['templates/foo.latte']);

		/** @var Class_ $resultClass */
		$resultClass = $result[0];
		/** @var ClassMethod $resultCtx0 */
		$resultCtx0 = $resultClass->stmts[0];
		/** @var ClassMethod $resultCtx1 */
		$resultCtx1 = $resultClass->stmts[1];

		self::assertNotNull($resultCtx0->stmts);
		self::assertCount(1, $resultCtx0->stmts);
		self::assertNotNull($resultCtx1->stmts);
		self::assertCount(0, $resultCtx1->stmts, 'edges land once, never duplicated into other clones');
	}

	/**
	 * @group latte2
	 */
	public function testProcessedOutputForTreeFixtureContainsSortedNeighborClassRefs(): void
	{
		$latte = "{varType string \$s}\n<p>{\$s}</p>\n";
		$compiled = (new LatteCompiler())->compile($latte, 'LatteTpl_test_edges');
		$declarations = (new DeclarationScanner())->scan($latte);

		$stmts = PipelineFactory::create()->process($compiled, $declarations);
		$result = (new DependencyEdgeEmitter())->emit($stmts, ['templates/foo.latte', 'templates/bar.latte']);

		$printed = (new Standard())->prettyPrintFile($result);

		$barClass = TemplateClassName::forPath('templates/bar.latte');
		$fooClass = TemplateClassName::forPath('templates/foo.latte');

		self::assertStringContainsString("Helpers::analyzed(\\$barClass::class);", $printed);
		self::assertStringContainsString("Helpers::analyzed(\\$fooClass::class);", $printed);

		$posBar = self::assertStrPos($printed, $barClass);
		$posFoo = self::assertStrPos($printed, $fooClass);
		self::assertTrue($posBar < $posFoo, 'neighbors must be emitted sorted by class name');
	}

	public function testEmitFingerprintNoClassReturnsStmtsUnchanged(): void
	{
		$stmts = [new Nop()];

		$result = (new DependencyEdgeEmitter())->emitFingerprint($stmts, 'deadbeef');

		self::assertSame($stmts, $result);
	}

	public function testEmitFingerprintInsertsPublicConstAsFirstClassStatement(): void
	{
		$existing = new ClassMethod('latteMain', ['stmts' => []]);
		$class = new Class_('LatteTpl_probe', ['stmts' => [$existing]]);

		$result = (new DependencyEdgeEmitter())->emitFingerprint([$class], 'deadbeef');

		/** @var Class_ $resultClass */
		$resultClass = $result[0];
		self::assertCount(2, $resultClass->stmts);

		$const = $resultClass->stmts[0];
		self::assertInstanceOf(ClassConst::class, $const);
		self::assertTrue($const->isPublic());
		self::assertFalse($const->isPrivate());
		self::assertSame($existing, $resultClass->stmts[1], 'original class statements are preserved after the const');

		$printed = (new Standard())->prettyPrint([$const]);
		self::assertSame("public const LATTE_EDGE_FINGERPRINT = 'deadbeef';", $printed);
	}

	/**
	 * @group latte2
	 */
	public function testEmitFingerprintProcessedOutputForTreeFixtureContainsConst(): void
	{
		$latte = "{varType string \$s}\n<p>{\$s}</p>\n";
		$compiled = (new LatteCompiler())->compile($latte, 'LatteTpl_test_fingerprint');
		$declarations = (new DeclarationScanner())->scan($latte);

		$stmts = PipelineFactory::create()->process($compiled, $declarations);
		$result = (new DependencyEdgeEmitter())->emitFingerprint($stmts, 'cafef00d');

		$printed = (new Standard())->prettyPrintFile($result);

		self::assertStringContainsString("public const LATTE_EDGE_FINGERPRINT = 'cafef00d';", $printed);
	}

	public function testEmitSliceRefsEmptyReturnsStmtsUnchanged(): void
	{
		$stmts = [new Nop()];

		$result = (new DependencyEdgeEmitter())->emitSliceRefs($stmts, []);

		self::assertSame($stmts, $result);
	}

	public function testEmitSliceRefsPrependsAnalyzedCallUsingSliceClassName(): void
	{
		$existing = new Nop();
		$main = new ClassMethod('latteMain', ['stmts' => [$existing]]);
		$class = new Class_('LatteTpl_probe', ['stmts' => [$main]]);

		$result = (new DependencyEdgeEmitter())->emitSliceRefs([$class], ['templates/foo.latte']);

		/** @var Class_ $resultClass */
		$resultClass = $result[0];
		/** @var ClassMethod $resultMain */
		$resultMain = $resultClass->stmts[0];
		self::assertNotNull($resultMain->stmts);
		self::assertCount(2, $resultMain->stmts);
		self::assertSame($existing, $resultMain->stmts[1]);

		$printed = (new Standard())->prettyPrint([$resultMain->stmts[0]]);
		self::assertSame(
			'\OriPhpstan\Nette\Latte\Runtime\Helpers::analyzed(\\' . SliceClassName::forPath('templates/foo.latte')
			. '::class);',
			$printed,
		);
	}

	public function testEmitSliceRefsSortsAndDedupesByClassName(): void
	{
		$main = new ClassMethod('latteMain', ['stmts' => []]);
		$class = new Class_('LatteTpl_probe', ['stmts' => [$main]]);

		$result = (new DependencyEdgeEmitter())->emitSliceRefs(
			[$class],
			['templates/foo.latte', 'templates/bar.latte', 'templates/foo.latte'],
		);

		/** @var Class_ $resultClass */
		$resultClass = $result[0];
		/** @var ClassMethod $resultMain */
		$resultMain = $resultClass->stmts[0];
		self::assertNotNull($resultMain->stmts);
		self::assertCount(2, $resultMain->stmts, 'duplicate includer rel path must be deduped to one call');

		$printed = (new Standard())->prettyPrint($resultMain->stmts);
		$barClass = SliceClassName::forPath('templates/bar.latte');
		$fooClass = SliceClassName::forPath('templates/foo.latte');

		$posBar = self::assertStrPos($printed, $barClass);
		$posFoo = self::assertStrPos($printed, $fooClass);
		self::assertTrue($posBar < $posFoo, 'slice refs must be emitted sorted by class name');
	}

	// emit() (LatteTpl_ neighbor refs) and emitSliceRefs() (LatteSlice_ includer refs) are two
	// independent prepends over the SAME main() body - both must coexist, neither ever clobbers
	// the other's already-prepended calls.
	public function testEmitSliceRefsComposesWithPriorEmitCall(): void
	{
		$existing = new Nop();
		$main = new ClassMethod('latteMain', ['stmts' => [$existing]]);
		$class = new Class_('LatteTpl_probe', ['stmts' => [$main]]);

		$emitter = new DependencyEdgeEmitter();
		$withNeighbor = $emitter->emit([$class], ['templates/target.latte']);
		$result = $emitter->emitSliceRefs($withNeighbor, ['templates/includer.latte']);

		/** @var Class_ $resultClass */
		$resultClass = $result[0];
		/** @var ClassMethod $resultMain */
		$resultMain = $resultClass->stmts[0];
		self::assertNotNull($resultMain->stmts);
		self::assertCount(3, $resultMain->stmts, 'both refs plus the original body statement');
		self::assertSame($existing, $resultMain->stmts[2], 'original body statement is preserved last');

		$printed = (new Standard())->prettyPrint($resultMain->stmts);
		self::assertStringContainsString(TemplateClassName::forPath('templates/target.latte'), $printed);
		self::assertStringContainsString(SliceClassName::forPath('templates/includer.latte'), $printed);
	}

	private static function assertStrPos(string $haystack, string $needle): int
	{
		$pos = strpos($haystack, $needle);
		self::assertNotFalse($pos, "expected to find '$needle' in printed output");

		return $pos;
	}

}
