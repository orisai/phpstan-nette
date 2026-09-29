<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Postprocess;

use OriPhpstan\Nette\Latte\Customs\TemplateTypeCustoms;
use OriPhpstan\Nette\Latte\Postprocess\FilterRewriter;
use OriPhpstan\Nette\Latte\Postprocess\FilterTable;
use OriPhpstan\Nette\Latte\Postprocess\FunctionTable;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Expression;
use PHPStan\Parser\Parser;
use PHPStan\Php\PhpVersion;
use PHPStan\Testing\PHPStanTestCase;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\EliminatorRun;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;
use Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures\ProcessParamsQualificationFixture;

final class FilterRewriterTest extends BaseTestCase
{

	public function testResolvedFilterCallCarriesProvenanceAttributeAndSourceLine(): void
	{
		$expression = $this->filtersAccessorExpression('webalize', 5);

		self::rewriter()->rewrite(
			[$expression],
			self::filterTable(),
			self::functionTable(),
		);

		$call = $expression->expr;
		self::assertInstanceOf(StaticCall::class, $call);
		self::assertSame('webalize', $call->getAttribute(FilterRewriter::FILTER_PROVENANCE_ATTRIBUTE));
		self::assertSame(5, $call->getStartLine());
		self::assertSame(5, $call->getEndLine());
	}

	// The literal, as-called name lands in the attribute, not the table's lowercased key - the
	// tip is meant to echo back exactly what the developer wrote in the template.
	public function testProvenanceAttributeUsesTheAsCalledCasingNotTheLowercasedTableKey(): void
	{
		$expression = $this->filtersAccessorExpression('webAlize', 1);

		self::rewriter()->rewrite(
			[$expression],
			self::filterTable(),
			self::functionTable(),
		);

		self::assertSame('webAlize', $expression->expr->getAttribute(FilterRewriter::FILTER_PROVENANCE_ATTRIBUTE));
	}

	public function testUnknownFilterFallbackNeverCarriesProvenanceAttributeButStillGetsALine(): void
	{
		$expression = $this->filtersAccessorExpression('definitelyNotAFilter', 3);

		self::rewriter()->rewrite(
			[$expression],
			self::filterTable(),
			self::functionTable(),
		);

		$call = $expression->expr;
		self::assertInstanceOf(StaticCall::class, $call);
		self::assertNull($call->getAttribute(FilterRewriter::FILTER_PROVENANCE_ATTRIBUTE));
		self::assertSame(3, $call->getStartLine());
	}

	// Spec §5d: functions never get a provenance tip - the author writes the call directly. The
	// no-tip marker (not just the absence of the filter attribute) is what LatteProvenanceTipRule
	// actually checks, so it must be present, not merely inferred from the filter attribute's
	// absence (an untagged node would otherwise still be eligible for the macro line-map fallback).
	public function testResolvedFunctionCallCarriesTheNoTipMarkerNeverTheFilterProvenanceAttribute(): void
	{
		$call = $this->rewriteGlobalFnCall('clamp', 7);

		self::assertNull($call->getAttribute(FilterRewriter::FILTER_PROVENANCE_ATTRIBUTE));
		self::assertTrue($call->getAttribute(FilterRewriter::FUNCTION_NO_TIP_ATTRIBUTE));
		self::assertSame(7, $call->getStartLine());
	}

	public function testUnknownFunctionFallbackAlsoCarriesTheNoTipMarker(): void
	{
		$call = $this->rewriteGlobalFnCall('definitelyNotAFunction', 9);

		self::assertNull($call->getAttribute(FilterRewriter::FILTER_PROVENANCE_ATTRIBUTE));
		self::assertTrue($call->getAttribute(FilterRewriter::FUNCTION_NO_TIP_ATTRIBUTE));
		self::assertSame(9, $call->getStartLine());
	}

	public function testFilterCallNeverCarriesTheFunctionNoTipMarker(): void
	{
		$expression = $this->filtersAccessorExpression('webalize', 1);

		self::rewriter()->rewrite(
			[$expression],
			self::filterTable(),
			self::functionTable(),
		);

		self::assertNull($expression->expr->getAttribute(FilterRewriter::FUNCTION_NO_TIP_ATTRIBUTE));
	}

	// A per-template filter dispatches through Helpers::templateTypeInstance() rather than a static
	// "Class::method" call - there is no real params instance at analysis time (see the helper's
	// own docblock) - and STILL carries the same provenance tip a harvested/built-in filter gets
	// (the tip mechanism keys off FILTER_PROVENANCE_ATTRIBUTE alone, not off which table
	// resolved the call).
	public function testPerTemplateFilterDispatchesViaTemplateTypeInstanceAndStillCarriesTheProvenanceTip(): void
	{
		$expression = $this->filtersAccessorExpression('docFilter', 4);

		self::rewriter()->rewrite(
			[$expression],
			self::filterTable(),
			self::functionTable(),
			ProcessParamsQualificationFixture::class,
			$this->templateTypeCustoms(),
		);

		$call = $expression->expr;
		self::assertInstanceOf(MethodCall::class, $call);
		self::assertSame('docFilter', $call->getAttribute(FilterRewriter::FILTER_PROVENANCE_ATTRIBUTE));

		$receiver = $call->var;
		self::assertInstanceOf(StaticCall::class, $receiver);
		self::assertInstanceOf(Identifier::class, $receiver->name);
		self::assertSame('templateTypeInstance', $receiver->name->toString());

		$classArg = $receiver->args[0];
		self::assertInstanceOf(Arg::class, $classArg);
		self::assertInstanceOf(ClassConstFetch::class, $classArg->value);
		self::assertInstanceOf(Name::class, $classArg->value->class);
		self::assertSame(ProcessParamsQualificationFixture::class, $classArg->value->class->toString());
	}

	// Scoping pin at the rewriter level: a call site whose
	// CURRENT file declares a DIFFERENT (or no) {templateType} class never reaches
	// ProcessParamsQualificationFixture's own per-template filter, even though a TemplateTypeCustoms
	// instance that COULD resolve it is wired in - resolveForTemplate() takes the declaring class as
	// an explicit argument, so an unrelated/null class here must fall through to the base table.
	public function testUnrelatedTemplateTypeClassNeverReachesAnotherClasssPerTemplateFilter(): void
	{
		$expression = $this->filtersAccessorExpression('docFilter', 1);

		self::rewriter()->rewrite(
			[$expression],
			self::filterTable(),
			self::functionTable(),
			self::class,
			$this->templateTypeCustoms(),
		);

		$call = $expression->expr;
		self::assertInstanceOf(StaticCall::class, $call);
		self::assertInstanceOf(Identifier::class, $call->name);
		self::assertSame('unknownFilter', $call->name->toString());
	}

	// Latte 3 passes the template as a function's first argument; the table entries take the
	// author's own arguments only.

	/**
	 * @group latte3
	 */
	public function testLatte3LeadingTemplateArgumentIsDroppedFromAFunctionCall(): void
	{
		$call = $this->rewriteGlobalFnCall('clamp', 7, [new Arg(new Variable('this')), new Arg(new Variable('x'))]);

		self::assertCount(1, $call->args);
		self::assertInstanceOf(Arg::class, $call->args[0]);
		self::assertInstanceOf(Variable::class, $call->args[0]->value);
		self::assertSame('x', $call->args[0]->value->name);
	}

	/**
	 * @group latte2
	 */
	public function testLatte2KeepsAFunctionCallsLeadingThisArgument(): void
	{
		$call = $this->rewriteGlobalFnCall('clamp', 7, [new Arg(new Variable('this')), new Arg(new Variable('x'))]);

		self::assertCount(2, $call->args);
	}

	/**
	 * @group latte3
	 */
	public function testLatte3InstanceMethodFilterDispatchesThroughATypedInstance(): void
	{
		$expression = $this->filtersAccessorExpression('number', 2);

		self::rewriter()->rewrite([$expression], self::filterTable(), self::functionTable());

		$call = $expression->expr;
		self::assertInstanceOf(MethodCall::class, $call);
		self::assertSame('number', $call->getAttribute(FilterRewriter::FILTER_PROVENANCE_ATTRIBUTE));
		$receiver = $call->var;
		self::assertInstanceOf(StaticCall::class, $receiver);
		self::assertInstanceOf(Identifier::class, $receiver->name);
		self::assertSame('templateTypeInstance', $receiver->name->toString());
		$classArg = $receiver->args[0];
		self::assertInstanceOf(Arg::class, $classArg);
		self::assertInstanceOf(ClassConstFetch::class, $classArg->value);
		self::assertInstanceOf(Name::class, $classArg->value->class);
		self::assertSame('Latte\Essential\Filters', $classArg->value->class->toString());
	}

	/**
	 * @dataProvider provideConvertToLines
	 */
	public function testConvertToOfTheFamilyGetsTheFilterInfoStandIn(string $latteLine, string $class): void
	{
		$fi = new Variable("\u{29F}_fi");
		$convertTo = new StaticCall(new Name($class), new Identifier('convertTo'), [
			new Arg($fi),
			new Arg(new String_('html')),
			new Arg(new Variable('s')),
		]);
		$other = new StaticCall(new Name('Latte\Runtime\Other'), new Identifier('convertTo'), [
			new Arg(new Variable("\u{29F}_fi")),
			new Arg(new String_('html')),
			new Arg(new Variable('s')),
		]);
		$stmts = [new Expression($convertTo), new Expression($other)];

		(new FilterRewriter(EliminatorRun::family($latteLine)))->rewrite(
			$stmts,
			self::filterTable(),
			self::functionTable(),
		);

		$rewritten = $convertTo->args[0];
		self::assertInstanceOf(Arg::class, $rewritten);
		self::assertInstanceOf(StaticCall::class, $rewritten->value);
		self::assertInstanceOf(Identifier::class, $rewritten->value->name);
		self::assertSame('filterInfo', $rewritten->value->name->toString());
		$kept = $other->args[0];
		self::assertInstanceOf(Arg::class, $kept);
		self::assertInstanceOf(Variable::class, $kept->value);
		self::assertSame($fi->name, $kept->value->name);
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public function provideConvertToLines(): iterable
	{
		yield 'latte 2' => [ShapeFamily::LATTE_2, 'Latte\Runtime\Filters'];
		yield 'latte 3.0' => [ShapeFamily::LATTE_30, 'Latte\Runtime\Filters'];
		yield 'latte 3.1' => [ShapeFamily::LATTE_31, 'Latte\Runtime\Helpers'];
	}

	private static function rewriter(): FilterRewriter
	{
		return new FilterRewriter(TestAdapter::factory()->family());
	}

	private static function filterTable(): FilterTable
	{
		return new FilterTable(TestAdapter::create()->defaultCallables());
	}

	private static function functionTable(): FunctionTable
	{
		return new FunctionTable(TestAdapter::create()->defaultCallables());
	}

	private function templateTypeCustoms(): TemplateTypeCustoms
	{
		/** @var Parser $parser */
		$parser = PHPStanTestCase::getContainer()->getService('currentPhpVersionSimpleParser');

		return new TemplateTypeCustoms(new PhpVersion(70400), $parser);
	}

	/**
	 * @param list<Arg>|null $args
	 */
	private function rewriteGlobalFnCall(string $functionName, int $line, ?array $args = null): StaticCall
	{
		$globalFetch = new PropertyFetch(new Variable('this'), 'global');
		$fnFetch = new PropertyFetch($globalFetch, 'fn');
		$nameFetch = new PropertyFetch($fnFetch, $functionName);
		$funcCall = new FuncCall(
			$nameFetch,
			$args ?? [new Arg(new Variable('x'))],
			['startLine' => $line, 'endLine' => $line],
		);
		$expression = new Expression($funcCall, ['startLine' => $line, 'endLine' => $line]);

		self::rewriter()->rewrite(
			[$expression],
			self::filterTable(),
			self::functionTable(),
		);

		$call = $expression->expr;
		self::assertInstanceOf(StaticCall::class, $call);

		return $call;
	}

	// FilterRewriter reads the ORIGINAL matched node's own getStartLine() (the way LineMapper's
	// real remap() would have already set it, before this rewriter ever runs) - the wrapping
	// Expression's own line is irrelevant to that lookup.
	private function filtersAccessorExpression(string $filterName, int $line): Expression
	{
		$filtersFetch = new PropertyFetch(new Variable('this'), 'filters');
		$nameFetch = new PropertyFetch($filtersFetch, $filterName);
		$funcCall = new FuncCall($nameFetch, [new Arg(new Variable('x'))], ['startLine' => $line, 'endLine' => $line]);

		return new Expression($funcCall, ['startLine' => $line, 'endLine' => $line]);
	}

}
