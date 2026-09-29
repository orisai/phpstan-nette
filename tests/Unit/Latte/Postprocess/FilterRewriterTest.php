<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Postprocess;

use OriPhpstan\Nette\Latte\Customs\TemplateTypeCustoms;
use OriPhpstan\Nette\Latte\Postprocess\FilterRewriter;
use OriPhpstan\Nette\Latte\Postprocess\FilterTable;
use OriPhpstan\Nette\Latte\Postprocess\FunctionTable;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Expression;
use PHPStan\Parser\Parser;
use PHPStan\Php\PhpVersion;
use PHPStan\Testing\PHPStanTestCase;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures\ProcessParamsQualificationFixture;

/**
 * @group latte2
 */
final class FilterRewriterTest extends BaseTestCase
{

	public function testResolvedFilterCallCarriesProvenanceAttributeAndSourceLine(): void
	{
		$expression = $this->filtersAccessorExpression('webalize', 5);

		(new FilterRewriter())->rewrite([$expression], new FilterTable(), new FunctionTable());

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

		(new FilterRewriter())->rewrite([$expression], new FilterTable(), new FunctionTable());

		self::assertSame('webAlize', $expression->expr->getAttribute(FilterRewriter::FILTER_PROVENANCE_ATTRIBUTE));
	}

	public function testUnknownFilterFallbackNeverCarriesProvenanceAttributeButStillGetsALine(): void
	{
		$expression = $this->filtersAccessorExpression('definitelyNotAFilter', 3);

		(new FilterRewriter())->rewrite([$expression], new FilterTable(), new FunctionTable());

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

		(new FilterRewriter())->rewrite([$expression], new FilterTable(), new FunctionTable());

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

		(new FilterRewriter())->rewrite(
			[$expression],
			new FilterTable(),
			new FunctionTable(),
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

		(new FilterRewriter())->rewrite(
			[$expression],
			new FilterTable(),
			new FunctionTable(),
			self::class,
			$this->templateTypeCustoms(),
		);

		$call = $expression->expr;
		self::assertInstanceOf(StaticCall::class, $call);
		self::assertInstanceOf(Identifier::class, $call->name);
		self::assertSame('unknownFilter', $call->name->toString());
	}

	private function templateTypeCustoms(): TemplateTypeCustoms
	{
		/** @var Parser $parser */
		$parser = PHPStanTestCase::getContainer()->getService('currentPhpVersionSimpleParser');

		return new TemplateTypeCustoms(new PhpVersion(70400), $parser);
	}

	private function rewriteGlobalFnCall(string $functionName, int $line): StaticCall
	{
		$globalFetch = new PropertyFetch(new Variable('this'), 'global');
		$fnFetch = new PropertyFetch($globalFetch, 'fn');
		$nameFetch = new PropertyFetch($fnFetch, $functionName);
		$funcCall = new FuncCall($nameFetch, [new Arg(new Variable('x'))], ['startLine' => $line, 'endLine' => $line]);
		$expression = new Expression($funcCall, ['startLine' => $line, 'endLine' => $line]);

		(new FilterRewriter())->rewrite([$expression], new FilterTable(), new FunctionTable());

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
