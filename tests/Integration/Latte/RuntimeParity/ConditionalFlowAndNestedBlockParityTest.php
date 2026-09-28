<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\RuntimeParity;

use Latte\Engine;
use Latte\Loaders\StringLoader;
use Nette\Utils\FileSystem;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function rtrim;

// Round 2 of the runtime-parity investigation for the "declaration consistency" design: probes
// for (A) conditional/loop EXECUTION FLOW reaching a same-file block, and (B) block-inside-block
// nesting - both against real Latte 2.11.7 (VERSION_ID 21107, this repo's spring-web container).
final class ConditionalFlowAndNestedBlockParityTest extends BaseTestCase
{

	public function testIfTrueBranchLocalReachesSameFileBlock(): void
	{
		// CoreMacros::macroIf compiles to a bare `if (EXPR) { ... }` - no scope boundary in PHP -
		// so a {var} assignment inside a taken branch is a real local in main()'s own frame by the
		// time {include b} runs. BlockMacros::finalize()'s get_defined_vars() placeholder (same
		// mechanism as the round-1 same-file-block probe) captures it like any other local: the
		// branch being conditional makes no difference once it actually ran.
		self::assertSame('cond-var', $this->render('cflow-cond-true.latte', []));
	}

	public function testIfFalseBranchLocalNeverReachesSameFileBlock(): void
	{
		// Mirror of the previous probe: the branch never executes, so the {var} assignment inside
		// it never runs and $x is genuinely undefined at the include site - not a scoping
		// difference from the true-branch case, just the assignment never happening.
		self::assertSame('UNDEF', $this->render('cflow-cond-false.latte', []));
	}

	public function testDoAssignmentReachesSameFileBlock(): void
	{
		// {do $x = 'do-mut'} compiles via CoreMacros::macroExpr to a plain passthrough PHP
		// statement (`%modify(%node.args) %node.line;`, no extract/assignment rewriting) - it is
		// exactly as visible to get_defined_vars() at a later same-file {include} as a {var}.
		self::assertSame('do-mut', $this->render('cflow-do-mutation.latte', []));
	}

	public function testPhpAssignmentReachesSameFileBlock(): void
	{
		// Same macroExpr passthrough as {do}, confirmed for the {php} tag as well.
		self::assertSame('php-mut', $this->render('cflow-php-mutation.latte', []));
	}

	public function testForeachLastIterationLocalReachesSameFileBlock(): void
	{
		// CoreMacros compiles {foreach} to a native PHP `foreach (...) { ... }` (no closure/scope
		// wrapper), so both the iteration variable and any {var} assigned inside the loop body are
		// ordinary locals that keep their LAST value after the loop ends - visible to a same-file
		// {include} placed after the loop, just like the round-1 same-file-block probe.
		self::assertSame('2', $this->render('cflow-foreach-last.latte', []));
	}

	public function testSameFileBlockIncludedFromInsideConditionSeesLocalsAtThatPoint(): void
	{
		// {include b} itself is just another statement inside the `if (true) { ... }` body - the
		// same get_defined_vars() call-site mechanism applies regardless of whether the include
		// statement is nested inside a condition or sits at main()'s top level.
		self::assertSame('in', $this->render('cflow-include-inside-if.latte', []));
	}

	public function testVarTypeInsideConditionHasZeroRuntimeEffect(): void
	{
		// CoreMacros::macroVarType emits no PHP at all (validates and returns void) regardless of
		// where the tag sits - confirmed here for a placement inside {if}, matching round-1 probe
		// 12's top-level/block-body confirmation: byte-identical output with and without the tag.
		$without = $this->render('cflow-vartype-control.latte', []);
		$with = $this->render('cflow-vartype-in-if.latte', []);
		self::assertSame('UNDEF', $without);
		self::assertSame($without, $with);
	}

	public function testNestedDefineCompilesAndInnerSeesOuterLocalsWhenIncludedFromWithinOuter(): void
	{
		// Legality: BlockMacros has no check anywhere that rejects a {define} nested inside another
		// {define}/{block} body - addBlock()'s only redeclaration guard keys on block NAME within
		// the current layer, not on syntactic nesting, and $this->index (the active layer) is only
		// ever changed by {embed}, never by {block}/{define}. A nested {define} therefore compiles
		// successfully into its own flat, independently addressable block method - same as its
		// enclosing block - so a nested {define} is LEGAL in Latte 2.11.7.
		//
		// Scope consequence: BlockMacros::finalize()'s get_defined_vars() placeholder substitution
		// is keyed purely on whether the target block is in the file's own compile-time registry -
		// it has no concept of "enclosing block" at all. So when {include inner} is called from a
		// call site that is itself INSIDE outer's compiled method body, the caller-locals rule
		// recurses exactly as it would from main(): inner receives outer's get_defined_vars() at
		// that call site, including $o.
		self::assertSame('outer-local', $this->render('nest-outer-inner.latte', []));
	}

	public function testNestedDefineIncludedFromFileTopLevelNeverSeesOuterBlocksLocals(): void
	{
		// Same fixture shape as the previous probe, but {include inner} is called from main()'s own
		// top level - a call site that is NEVER inside outer's compiled method frame, since outer's
		// body only runs if and when {include outer} itself is invoked (never, in this fixture). $o
		// is a local of outer's own call frame exclusively; from main()'s frame it was never
		// assigned anywhere, so inner reads it as undefined - the same block-as-function-scope
		// boundary as the round-1 leakage-out probe, unaffected by lexical nesting.
		self::assertSame('UNDEF', $this->render('nest-inner-from-top.latte', []));
	}

	public function testNestedDefineParamDefaultNeverSeesOuterLocal(): void
	{
		// BlockMacros::macroDefine's compiled param formula (`$p = $ʟ_args[0] ?? $ʟ_args['p'] ?? 'PARAM-DEFAULT';`)
		// is identical for a nested {define} as for a top-level one - it doesn't consult the
		// enclosing block's frame at all, only $ʟ_args. {include inner} with no args resolves an
		// empty $ʟ_args (inner has declared params, so BlockMacros::finalize()'s placeholder is the
		// literal '[]', not get_defined_vars() - same rule as round-1 probe 10a), so the param falls
		// through to its own literal default.
		self::assertSame('PARAM-DEFAULT', $this->render('nest-param-default.latte', []));
	}

	public function testNestedDefineParamExplicitArgWinsOverDefault(): void
	{
		// Same compiled formula as the previous probe: a positional {include inner, 'arg'} call
		// short-circuits `$ʟ_args[0] ?? ...` to the call's own argument, exactly as round-1 probe
		// 10b showed for a top-level block.
		self::assertSame('arg', $this->render('nest-param-arg.latte', []));
	}

	public function testVarTypeInsideNestedDefineHasZeroRuntimeEffect(): void
	{
		// Same zero-emission behavior as the top-level/condition-scoped cases above, now inside a
		// block nested inside another block: {varType} never emits PHP regardless of nesting depth.
		$without = $this->render('nest-vartype-without.latte', []);
		$with = $this->render('nest-vartype-with.latte', []);
		self::assertSame('UNDEF', $without);
		self::assertSame($without, $with);
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function render(string $fixture, array $params): string
	{
		$engine = new Engine();
		$engine->setLoader(new StringLoader(['probe' => FileSystem::read(__DIR__ . '/Fixtures/' . $fixture)]));

		return rtrim($engine->renderToString('probe', $params), "\n");
	}

}
