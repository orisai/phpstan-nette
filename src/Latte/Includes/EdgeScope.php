<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Includes;

use function array_merge;
use function in_array;

final class EdgeScope
{

	private const LAYOUT_TAGS = ['layout', 'extends'];

	// The tags orisaiNette.latte.includeIsolation restricts to their explicit args. {includeblock} is
	// absent: a Latte-2-only alias Latte 3 removed.
	private const ISOLATING_TAGS = ['include', 'embed'];

	private function __construct()
	{
	}

	// Shared with ContextResolver::edgeProvenance() so the "does this edge inherit
	// the includer's top-level locals" check can never drift from resolve()'s own LAYOUT_TAGS branch.
	public static function isLayoutTag(string $tag): bool
	{
		return in_array($tag, self::LAYOUT_TAGS, true);
	}

	// Computes the per-tag scope an edge PROVIDES to its target, before any narrowing (see
	// CapturedOverlay) or declared-target overlay is applied - the one piece of logic
	// ContextResolver::buildEdgeContext and IncludeContractChecker::checkDeclaredVar must never
	// compute independently (a drift between the two produces either a false
	// includeMissingVariable/includeTypeMismatch, or a missed one, depending on which side is
	// stricter). Both callers must also route the result through CapturedOverlay before their own
	// declared-target overlay, for the same reason.

	/**
	 * @param callable(): array<string, string> $includerTopLevelVars lazy: only layout/extends reads it
	 * @return array{vars: array<string, string>, namedKeys: array<string, string>, open: bool}
	 */
	public static function resolve(
		IncludeTarget $site,
		TemplateContext $context,
		ArgTyper $argTyper,
		callable $includerTopLevelVars,
		bool $includeIsolation
	): array
	{
		$tag = $site->getTag();

		if ($tag === 'import') {
			return ['vars' => $context->getVars(), 'namedKeys' => [], 'open' => false];
		}

		$typed = $argTyper->typeArgs($site, $context);

		if (in_array($tag, self::LAYOUT_TAGS, true)) {
			// The child's finished main scope by the time control passes to the layout: its own
			// context plus its own top-level locals (unlike include, which never sees the includer's
			// body-level {var} assignments), overlaid with the site's explicit args - Latte 3.1's
			// `{extends file, args}` renders the parent with `$this->parentArgs + $params` (Latte 2
			// and 3.0 accept no args, so the overlay is empty there).
			return [
				'vars' => array_merge($context->getVars(), $includerTopLevelVars(), $typed['vars']),
				'namedKeys' => $typed['vars'],
				'open' => $typed['open'],
			];
		}

		if ($tag === 'sandbox') {
			return ['vars' => $typed['vars'], 'namedKeys' => $typed['vars'], 'open' => $typed['open']];
		}

		// On every supported Latte line a file {include} gives its target the includer's render params
		// overlaid with the site's explicit args, never its {var} locals (IncludeSemanticsParityTest).
		// orisaiNette.latte.includeIsolation is an opt-in stricter model: explicit args only, the sandbox
		// shape. Block dispatch, layout/extends and import are separate mechanisms it leaves alone.
		if (
			$includeIsolation
			&& in_array($tag, self::ISOLATING_TAGS, true)
			&& $site->getKind() === IncludeTarget::KIND_STATIC_FILE
		) {
			return ['vars' => $typed['vars'], 'namedKeys' => $typed['vars'], 'open' => $typed['open']];
		}

		// include, embed (file-form): approximated identically - both inherit the includer's
		// resolved scope, overlaid with the site's own typed explicit args. For embed that is a
		// deliberate OVER-approximation even under Latte 2: BlockMacros::macroEmbed emits
		// `createTemplate($name, %node.array, "embed")` with no `+ $this->params` term
		// (IncludeSemanticsParityTest), so a real embed target sees only its explicit args - modelling
		// more can miss findings, never invent them.
		return [
			'vars' => array_merge($context->getVars(), $typed['vars']),
			'namedKeys' => $typed['vars'],
			'open' => $typed['open'],
		];
	}

}
