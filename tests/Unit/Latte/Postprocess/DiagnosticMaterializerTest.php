<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Postprocess;

use OriPhpstan\Nette\Latte\Compile\Diagnostic;
use OriPhpstan\Nette\Latte\Postprocess\DiagnosticMaterializer;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Nop;
use PhpParser\PrettyPrinter\Standard;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function strpos;

final class DiagnosticMaterializerTest extends BaseTestCase
{

	public function testEmptyDiagnosticsReturnsStmtsUnchanged(): void
	{
		$stmts = [new Nop()];

		$result = (new DiagnosticMaterializer())->materialize($stmts, [], 'LatteTpl_unused');

		self::assertSame($stmts, $result);
	}

	public function testSingleDiagnosticInsertedAtMainHeadWithLatteLine(): void
	{
		$existing = new Nop();
		$main = new ClassMethod('latteMain', ['stmts' => [$existing]]);
		$class = new Class_('LatteTpl_probe', ['stmts' => [$main]]);

		$diagnostic = new Diagnostic('orisai.nette.latte.unknownFilter', "Unknown Latte filter 'x'.", 5);

		$result = (new DiagnosticMaterializer())->materialize([$class], [$diagnostic], 'LatteTpl_probe');

		self::assertCount(1, $result);
		/** @var Class_ $resultClass */
		$resultClass = $result[0];
		/** @var ClassMethod $resultMain */
		$resultMain = $resultClass->stmts[0];
		self::assertNotNull($resultMain->stmts);
		self::assertCount(2, $resultMain->stmts);

		$reportStmt = $resultMain->stmts[0];
		self::assertInstanceOf(Expression::class, $reportStmt);
		self::assertSame(5, $reportStmt->getStartLine());
		self::assertSame(
			$existing,
			$resultMain->stmts[1],
			'original body statements are preserved after the diagnostic',
		);

		$printed = (new Standard())->prettyPrint([$reportStmt]);
		self::assertStringContainsString(
			"Diag::report('orisai.nette.latte.unknownFilter', 'Unknown Latte filter \\'x\\'.')",
			$printed,
		);
	}

	public function testMultipleDiagnosticsOrderedByLineStable(): void
	{
		$main = new ClassMethod('latteMain', ['stmts' => []]);
		$class = new Class_('LatteTpl_probe', ['stmts' => [$main]]);

		$diagnostics = [
			new Diagnostic('latte.a', 'first at line 3', 3),
			new Diagnostic('latte.b', 'second at line 3', 3),
			new Diagnostic('latte.c', 'at line 1', 1),
		];

		$result = (new DiagnosticMaterializer())->materialize([$class], $diagnostics, 'LatteTpl_probe');

		/** @var Class_ $resultClass */
		$resultClass = $result[0];
		/** @var ClassMethod $resultMain */
		$resultMain = $resultClass->stmts[0];
		self::assertNotNull($resultMain->stmts);
		self::assertCount(3, $resultMain->stmts);

		$printed = (new Standard())->prettyPrint($resultMain->stmts);
		$posC = self::assertStrPos($printed, "'latte.c'");
		$posA = self::assertStrPos($printed, "'latte.a'");
		$posB = self::assertStrPos($printed, "'latte.b'");

		self::assertTrue($posC < $posA, 'lowest line (1) must come first');
		self::assertTrue($posA < $posB, 'equal-line diagnostics must keep their original relative order');
	}

	public function testFailureResultBuildsMinimalClassWithDeterministicName(): void
	{
		$diagnostic = new Diagnostic('orisai.nette.latte.parseError', 'boom', 3);

		$result = (new DiagnosticMaterializer())->materialize([], [$diagnostic], 'LatteTpl_failure_x');

		self::assertCount(1, $result);
		$class = $result[0];
		self::assertInstanceOf(Class_::class, $class);
		self::assertNotNull($class->name);
		self::assertSame('LatteTpl_failure_x', $class->name->toString());

		self::assertNotNull($class->extends);
		self::assertSame('Latte\Runtime\Template', $class->extends->toString());
		self::assertNotNull($class->namespacedName);
		self::assertSame('LatteTpl_failure_x', $class->namespacedName->toString());

		self::assertCount(1, $class->stmts);
		$main = $class->stmts[0];
		self::assertInstanceOf(ClassMethod::class, $main);
		self::assertSame('latteMain', $main->name->toString());
		self::assertInstanceOf(Identifier::class, $main->returnType);
		self::assertSame('void', $main->returnType->toString());
		self::assertNotNull($main->stmts);
		self::assertCount(1, $main->stmts);
		self::assertSame(3, $main->stmts[0]->getStartLine());
	}

	public function testNonEmptyStmtsWithoutMainFallsBackToTopLevelStatements(): void
	{
		$original = new Nop();
		$diagnostic = new Diagnostic('orisai.nette.latte.parseError', 'boom', 2);

		$result = (new DiagnosticMaterializer())->materialize([$original], [$diagnostic], 'LatteTpl_unused');

		self::assertCount(2, $result);
		self::assertInstanceOf(Expression::class, $result[0]);
		self::assertSame($original, $result[1]);
	}

	public function testDiagnosticLandsInFirstContextCloneWhenPlainMainWasReplaced(): void
	{
		// DeclarationInjector removes latteMain entirely once contexts are non-empty, replacing it
		// with N latteMain_ctx{i} clones; diagnostics must still land once, in the first (ctx0) clone.
		$existing = new Nop();
		$ctx0 = new ClassMethod('latteMain_ctx0', ['stmts' => [$existing]]);
		$ctx1 = new ClassMethod('latteMain_ctx1', ['stmts' => []]);
		$class = new Class_('LatteTpl_probe', ['stmts' => [$ctx0, $ctx1]]);

		$diagnostic = new Diagnostic('orisai.nette.latte.unknownFilter', "Unknown Latte filter 'x'.", 5);

		$result = (new DiagnosticMaterializer())->materialize([$class], [$diagnostic], 'LatteTpl_probe');

		/** @var Class_ $resultClass */
		$resultClass = $result[0];
		/** @var ClassMethod $resultCtx0 */
		$resultCtx0 = $resultClass->stmts[0];
		/** @var ClassMethod $resultCtx1 */
		$resultCtx1 = $resultClass->stmts[1];

		self::assertSame('latteMain_ctx0', $resultCtx0->name->toString());
		self::assertNotNull($resultCtx0->stmts);
		self::assertCount(2, $resultCtx0->stmts);
		self::assertSame(
			$existing,
			$resultCtx0->stmts[1],
			'original body statements are preserved after the diagnostic',
		);

		self::assertNotNull($resultCtx1->stmts);
		self::assertCount(0, $resultCtx1->stmts, 'diagnostics land once, never duplicated into other clones');
	}

	public function testClassWithUnrenamedMainFallsBackToTopLevelStatements(): void
	{
		// DeclarationInjector renames main() to latteMain() before this ever runs; a class that
		// still has a plain 'main' method (any shape this pipeline doesn't itself produce) must be
		// treated the same as "no main found", never matched by name coincidence.
		$existing = new Nop();
		$main = new ClassMethod('main', ['stmts' => [$existing]]);
		$class = new Class_('LatteTpl_probe', ['stmts' => [$main]]);
		$diagnostic = new Diagnostic('orisai.nette.latte.parseError', 'boom', 2);

		$result = (new DiagnosticMaterializer())->materialize([$class], [$diagnostic], 'LatteTpl_probe');

		self::assertCount(2, $result);
		self::assertInstanceOf(Expression::class, $result[0]);
		self::assertSame($class, $result[1]);
	}

	private static function assertStrPos(string $haystack, string $needle): int
	{
		$pos = strpos($haystack, $needle);
		self::assertNotFalse($pos, "expected to find '$needle' in printed output");

		return $pos;
	}

}
