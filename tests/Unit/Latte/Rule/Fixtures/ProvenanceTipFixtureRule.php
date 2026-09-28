<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Rule\Fixtures;

use PhpParser\Node;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use OriPhpstan\Nette\Latte\Postprocess\FilterRewriter;
use OriPhpstan\Nette\Latte\Rule\LatteDebugDumpRule;

// Decorator-level fixture for LatteProvenanceTipRuleTest: simulates "a rule fired on a
// filter-rewritten node" by tagging the node itself (FilterRewriter's own attribute-setting is
// covered separately by FilterRewriterTest) - keeps the decorator's own logic testable through
// RuleTestCase's real dispatch (a raw hand-built Scope mock fails PHPStan's own analysis of the
// phar's Rule::processNode() contract, which narrows $scope beyond the public interface).
/**
 * @implements Rule<ConstFetch>
 */
final class ProvenanceTipFixtureRule implements Rule
{

	public function getNodeType(): string
	{
		return ConstFetch::class;
	}

	/**
	 * @return list<IdentifierRuleError>
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		$name = $node->name->toLowerString();

		if ($name === 'true') {
			$node->setAttribute(FilterRewriter::FILTER_PROVENANCE_ATTRIBUTE, 'demoFilter');

			return [
				RuleErrorBuilder::message('demo filter error.')
					->identifier('test.provenanceTipFixture')
					->metadata(['demo' => true])
					->nonIgnorable()
					->fixNode($node, static fn (ConstFetch $node): Node => new ConstFetch(new Name('false')))
					->build(),
			];
		}

		if ($name === 'false') {
			$node->setAttribute(FilterRewriter::FILTER_PROVENANCE_ATTRIBUTE, 'demoFilter');

			return [
				RuleErrorBuilder::message('demo filter error with own tip.')
					->identifier('test.provenanceTipFixture')
					->tip('Existing tip.')
					->build(),
			];
		}

		if ($name === 'null') {
			return [
				RuleErrorBuilder::message('plain error.')
					->identifier('test.provenanceTipFixture')
					->build(),
			];
		}

		// Same filter-attribute tagging as the 'true' scenario above - would earn a tip like every
		// other tagged node, EXCEPT LatteProvenanceTipRule excludes this identifier unconditionally.
		if ($name === 'debugdump') {
			$node->setAttribute(FilterRewriter::FILTER_PROVENANCE_ATTRIBUTE, 'demoFilter');

			return [
				RuleErrorBuilder::message('debug dump passthrough error.')
					->identifier(LatteDebugDumpRule::IDENTIFIER)
					->nonIgnorable()
					->build(),
			];
		}

		return [];
	}

}
