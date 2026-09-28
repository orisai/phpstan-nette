<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Declarations;

use InvalidArgumentException;
use Latte\CompileException;
use Latte\MacroTokens;
use Latte\Parser;
use Latte\RegexpException;
use Latte\Token;
use OriPhpstan\Nette\Latte\Includes\BlockBodyTracker;
use OriPhpstan\Nette\Latte\Includes\MacroPairing;
use function array_key_exists;
use function array_keys;
use function array_pop;
use function array_unshift;
use function count;
use function end;
use function in_array;
use function ltrim;
use function sha1;
use function strpos;
use function trim;

final class DeclarationScanner
{

	private const HEAD_ALLOWED_TAGS = [
		'import',
		'extends',
		'layout',
		'contentType',
		'parameters',
		'varType',
		'varPrint',
		'templateType',
		'templatePrint',
	];

	private const IMPLICIT_PRINT_TAG = '=';

	// n:inner-foreach differs from n:foreach only in whether the element itself is repeated; both
	// compile to a Foreach_ binding the same variables.
	private const FOREACH_ATTRIBUTES = ['n:foreach', 'n:inner-foreach'];

	/** @var array<string, Declarations> */
	private array $memo = [];

	public function scan(string $latteSource): Declarations
	{
		$key = sha1($latteSource);
		if (array_key_exists($key, $this->memo)) {
			return $this->memo[$key];
		}

		try {
			$declarations = $this->doScan($latteSource);
		} catch (CompileException | RegexpException | InvalidArgumentException $e) {
			// The compiler tokenizes/compiles the same source independently (LatteCompiler::compile)
			// and reports its own orisaiNette.latte.parseError diagnostic for malformed input; this scanner only
			// extracts declarations for injection, so degrading to empty here loses no diagnostic.
			$declarations = $this->empty();
		}

		return $this->memo[$key] = $declarations;
	}

	private function doScan(string $latteSource): Declarations
	{
		$tokens = (new Parser())->parse($latteSource);

		$inHead = true;
		$templateTypeClass = null;
		$templateTypeLine = null;
		$headerVarTypes = [];
		$headerVarTypeLines = [];
		$midFileVarTypes = [];
		$typedVars = [];
		$typedDefaults = [];
		$parameters = null;
		$defineParams = [];
		$defineParamDefaults = [];
		// [token index, name, type, line, inside a block body] per PLACEMENT-CHECKABLE {varType}:
		// mid-file and outside any {block}/{define} body's own top level. A block-body one is that
		// block's input contract (TemplateFactExtractor records it as a blockDeclaredVar) - a
		// parameter declaration exactly like a header one, so it is exempt for the same reason.
		$placementCandidates = [];
		$blocks = new BlockBodyTracker();
		// Body depth of every open construct Latte compiles to its OWN method - a different
		// question from the tracker's own named-block stack (that one exists to decide whether a
		// tag is a block's input contract, and deliberately ignores a dynamically-named block),
		// so it is kept here rather than in the shared tracker. The tracker still owns the depth
		// arithmetic both stacks pop against; nothing about the pairing rules is re-implemented.
		/** @var list<int> $ownMethodBodyDepths */
		$ownMethodBodyDepths = [];

		foreach ($tokens as $index => $token) {
			if ($inHead && !$this->keepsHead($token)) {
				$inHead = false;
			}

			if ($token->type !== Token::MACRO_TAG) {
				continue;
			}

			// A closing tag carries no arguments, so none of the cases below can match one.
			if ($token->closing) {
				$blocks->closing($token);
				$ownMethodBodyDepths = $this->popClosedBodies($ownMethodBodyDepths, $blocks->depth());

				continue;
			}

			$opensBody = MacroPairing::opensBody($tokens, (int) $index);

			// Latte's tag regex can misparse "|" inside args (e.g. "int|null $x") as a
			// {tag args|modifier} filter suffix; every vendor handler reconcatenates it first.
			$value = $token->value . $token->modifiers;

			switch ($token->name) {
				case 'templateType':
					if ($templateTypeClass === null) {
						$templateTypeClass = ltrim(trim($value), '\\');
						$templateTypeLine = $token->line;
					}

					break;
				case 'varType':
					$entry = $this->scanVarType($value);
					if ($entry !== null) {
						[$name, $type] = $entry;
						if ($inHead) {
							$headerVarTypes[$name] = $type;
							$headerVarTypeLines[$name] = $token->line;
						} else {
							$midFileVarTypes[] = [$name, $type, $token->line];
							if (!$blocks->isAtOwnTopLevel()) {
								$placementCandidates[] = [
									(int) $index,
									$name,
									$type,
									$token->line,
									$ownMethodBodyDepths !== [],
								];
							}
						}
					}

					break;
				case 'var':
					foreach ($this->scanVarDeclarations($value, $token->line) as [$varName, $varTypeName, $varLine]) {
						if ($varTypeName !== null) {
							$typedVars[] = [$varName, $varTypeName, $varLine];
						}
					}

					break;
				case 'default':
					foreach ($this->scanVarDeclarations($value, $token->line) as [$varName, $varTypeName, $varLine]) {
						if ($varTypeName !== null) {
							$typedDefaults[] = [$varName, $varTypeName, $varLine];
						}
					}

					break;
				case 'parameters':
					if ($parameters === null) {
						$parameters = [];
					}

					foreach ($this->scanParameters($value, $token->line) as $entry) {
						$parameters[] = $entry;
					}

					break;
				case 'block':
				case 'define':
					// {block} never carries params (BlockMacros rejects them at compile time -
					// "Unexpected arguments ... in {block}"), so reusing scanDefine() here always
					// yields an empty own-param list for it, identical to a param-less {define}: the
					// only thing a {block} tag contributes to $defineParams is its own name, needed
					// so DeclarationInjector's blockNameMap/defineMethodMap treat {block} and
					// {define} methods alike (F4's args channel and capturedBlockArgTypes() must see
					// a {block} target the same way they already see a {define} one).
					[$defineName, $defineBlockParams, $defineBlockParamDefaults] = $this->scanDefine($value);
					if ($defineName !== '') {
						$defineParams[$defineName] = $defineBlockParams;
						$defineParamDefaults[$defineName] = $defineBlockParamDefaults;
					}

					if ($opensBody) {
						$blocks->enterBlockOrDefine($defineName);
						// An ANONYMOUS {block} is compiled inline into main() (BlockMacros::macroBlock
						// returns early for an empty name, wrapping the body in ob_start() at most); a
						// named one - dynamic included, via beginDynamicBlockOrDefine() - always gets
						// its own method.
						if ($defineName !== '') {
							$ownMethodBodyDepths[] = $blocks->depth() + 1;
						}
					}

					break;
				case 'snippet':
				case 'snippetArea':
					// Not a $defineParams contributor (a snippet declares no parameters and is not an
					// {include} target the args channel types), but BlockMacros::macroSnippet /
					// macroSnippetArea do extractMethod() it, so its body is its own compiled method
					// exactly like a {block}'s. A DYNAMICALLY named {snippet} is the one exception:
					// beginDynamicSnippet() emits the body inline into main() instead.
					if ($opensBody) {
						[$snippetName] = $this->scanDefine($value);
						$dynamic = $token->name === 'snippet' && $this->isDynamicName($snippetName);
						if ($snippetName !== '' && !$dynamic) {
							$ownMethodBodyDepths[] = $blocks->depth() + 1;
						}
					}

					break;
			}

			$blocks->advance($opensBody);
		}

		// Every name a header {varType} or {parameters} entry declares is a real PHP parameter,
		// known in scope from the template's very first line onward - the same standing a
		// function parameter has for WrongVariableNameInVarTagRule::processAssign()'s own
		// `hasVariableType()` guard.
		$headerNames = array_keys($headerVarTypes);
		foreach ($parameters ?? [] as [, $paramName]) {
			$headerNames[] = $paramName;
		}

		return new Declarations(
			$templateTypeClass,
			$templateTypeLine,
			$headerVarTypes,
			$headerVarTypeLines,
			$midFileVarTypes,
			$typedVars,
			$typedDefaults,
			$parameters,
			$defineParams,
			$defineParamDefaults,
			$this->resolvePlacements($tokens, $placementCandidates, $headerNames),
		);
	}

	private function keepsHead(Token $token): bool
	{
		return $token->type === Token::COMMENT
			|| ($token->type === Token::MACRO_TAG && in_array($token->name, self::HEAD_ALLOWED_TAGS, true))
			|| ($token->type === Token::TEXT && trim($token->text) === '');
	}

	/**
	 * @return array{string, string}|null
	 */
	private function scanVarType(string $value): ?array
	{
		$tokens = new MacroTokens($value);
		$type = trim($tokens->joinUntil(MacroTokens::T_VARIABLE));
		$variable = $tokens->nextValue(MacroTokens::T_VARIABLE);

		if ($type === '' || $variable === null) {
			return null;
		}

		return [ltrim($variable, '$'), $type];
	}

	// A {varType} run's anchor is the first construct after it that is not another {varType} and
	// not skippable filler, mirroring keepsHead()'s own notion of what does not count as content.
	// Every {varType} sharing one anchor forms a RUN, which is what PHPStan's own
	// differentVariable/variableNotFound split keys on (its $varTags map is exactly this run).

	/**
	 * @param array<int, Token> $tokens
	 * @param list<array{int, string, string, int, bool}> $candidates
	 * @param list<string> $headerNames
	 * @return list<VarTypePlacement>
	 */
	private function resolvePlacements(array $tokens, array $candidates, array $headerNames): array
	{
		$anchorIndexes = [];
		$runSizes = [];
		foreach ($candidates as $position => [$index]) {
			$next = $this->nextAnchorIndex($tokens, $index);
			$anchorIndexes[$position] = $next;
			$runSizes[$next ?? -1] = ($runSizes[$next ?? -1] ?? 0) + 1;
		}

		// Computed at most once per template, and only for one that actually has an anchorless
		// mid-file {varType} - a template with none never pays for the extra pass.
		$assignedNames = false;

		$placements = [];
		foreach ($candidates as $position => [$index, $name, $type, $line, $insideBlockBody]) {
			$nextIndex = $anchorIndexes[$position];
			$described = $nextIndex === null
				? ['kind' => null, 'line' => null, 'bound' => null, 'label' => 'the end of the template']
				: $this->describeAnchor($tokens, $nextIndex);

			if ($described['kind'] === null && $assignedNames === false) {
				$assignedNames = $this->scanAssignedNames($tokens);
			}

			// Mirrors processAssign()'s `!$scope->hasVariableType($key)->no()` guard: a name already
			// known in scope before this tag is accepted regardless of whether it matches THIS
			// anchor. Scoped to non-foreach anchors only, matching upstream exactly - processForeach()
			// has no such guard, so a foreach mismatch is reported even for an otherwise-known name.
			$knownBeforeTag = $described['kind'] !== null
				&& $described['kind'] !== VarTypePlacement::ANCHOR_FOREACH
				&& $described['bound'] !== null
				&& !in_array($name, $described['bound'], true)
				&& $this->isNameBoundBefore($tokens, $index, $name, $headerNames);

			$placements[] = new VarTypePlacement(
				$name,
				$type,
				$line,
				$described['kind'],
				$described['line'],
				$described['label'],
				$described['bound'],
				$runSizes[$nextIndex ?? -1],
				$described['kind'] === null && $assignedNames !== false && !in_array($name, $assignedNames, true),
				$knownBeforeTag,
				$insideBlockBody,
			);
		}

		return $placements;
	}

	// Whether $name is already bound by something strictly BEFORE $beforeIndex: a header
	// parameter (known from the template's first line) or an earlier {var}/{default}/{capture}/
	// {foreach}/{php}/{do} assignment. An earlier construct this scanner cannot resolve to a plain
	// assignment (an opaque {php}/{do} body) might bind anything, so it is treated the same as a
	// match - the same MAYBE-counts-as-YES direction `hasVariableType()->no()` takes.

	/**
	 * @param array<int, Token> $tokens
	 * @param list<string> $headerNames
	 */
	private function isNameBoundBefore(array $tokens, int $beforeIndex, string $name, array $headerNames): bool
	{
		if (in_array($name, $headerNames, true)) {
			return true;
		}

		foreach ($tokens as $index => $token) {
			if ($index >= $beforeIndex) {
				break;
			}

			if (
				$token->type === Token::HTML_ATTRIBUTE_BEGIN
				&& in_array($token->name, self::FOREACH_ATTRIBUTES, true)
				&& $token->value !== null
				&& in_array($name, $this->scanForeachBindings($token->value), true)
			) {
				return true;
			}

			if ($token->type !== Token::MACRO_TAG || $token->closing) {
				continue;
			}

			$described = $this->describeAnchor($tokens, $index);
			if ($described['kind'] === null) {
				continue;
			}

			if ($described['bound'] === null || in_array($name, $described['bound'], true)) {
				return true;
			}
		}

		return false;
	}

	// Every variable name bound ANYWHERE in the template, by any construct that could serve as an
	// anchor - the signal that separates a genuinely misplaced local declaration from a template
	// PARAMETER whose {varType} lost its header position to a preceding non-header tag, which is a
	// different mistake with a different fix. Derived through describeAnchor() so the binding
	// vocabulary can never drift from the anchor vocabulary.
	//
	// A construct whose bindings this scanner cannot resolve (an opaque {php}/{do} body) makes the
	// whole answer unknown rather than incomplete: the caller must not claim a variable is never
	// assigned when some statement it cannot read might assign it.

	/**
	 * @param array<int, Token> $tokens
	 * @return list<string>|false
	 */
	private function scanAssignedNames(array $tokens)
	{
		$names = [];
		foreach ($tokens as $index => $token) {
			if (
				$token->type === Token::HTML_ATTRIBUTE_BEGIN
				&& in_array($token->name, self::FOREACH_ATTRIBUTES, true)
				&& $token->value !== null
			) {
				foreach ($this->scanForeachBindings($token->value) as $name) {
					$names[$name] = true;
				}

				continue;
			}

			if ($token->type !== Token::MACRO_TAG || $token->closing) {
				continue;
			}

			$described = $this->describeAnchor($tokens, $index);
			if ($described['kind'] === null) {
				continue;
			}

			if ($described['bound'] === null) {
				return false;
			}

			foreach ($described['bound'] as $name) {
				$names[$name] = true;
			}
		}

		return array_keys($names);
	}

	/**
	 * @param array<int, Token> $tokens
	 */
	private function nextAnchorIndex(array $tokens, int $index): ?int
	{
		$count = count($tokens);
		for ($i = $index + 1; $i < $count; $i++) {
			$token = $tokens[$i];
			if ($token->type === Token::COMMENT) {
				continue;
			}

			if ($token->type === Token::TEXT && trim($token->text) === '') {
				continue;
			}

			if ($token->type === Token::MACRO_TAG && $token->name === 'varType' && !$token->closing) {
				continue;
			}

			return $i;
		}

		return null;
	}

	/**
	 * @param array<int, Token> $tokens
	 * @return array{kind: VarTypePlacement::ANCHOR_*|null, line: int|null, bound: list<string>|null, label: string}
	 */
	private function describeAnchor(array $tokens, int $index): array
	{
		$token = $tokens[$index];

		if ($token->type === Token::HTML_TAG_BEGIN) {
			$foreachArgs = $this->findForeachAttribute($tokens, $index);
			if ($foreachArgs !== null) {
				return [
					'kind' => VarTypePlacement::ANCHOR_FOREACH,
					'line' => $token->line,
					'bound' => $this->scanForeachBindings($foreachArgs),
					'label' => '<' . $token->name . '>',
				];
			}

			return ['kind' => null, 'line' => null, 'bound' => null, 'label' => '<' . $token->name . '>'];
		}

		if ($token->type !== Token::MACRO_TAG) {
			return ['kind' => null, 'line' => null, 'bound' => null, 'label' => 'template output'];
		}

		if ($token->closing) {
			return ['kind' => null, 'line' => null, 'bound' => null, 'label' => '{/' . $token->name . '}'];
		}

		$label = $token->name === self::IMPLICIT_PRINT_TAG ? 'an output tag' : '{' . $token->name . '}';
		$value = $token->value . $token->modifiers;

		switch ($token->name) {
			case 'var':
			case 'default':
				$bound = [];
				foreach ($this->scanVarDeclarations($value, $token->line) as [$name]) {
					$bound[] = $name;
				}

				return ['kind' => VarTypePlacement::ANCHOR_ASSIGN, 'line' => $token->line, 'bound' => $bound, 'label' => $label];
			case 'capture':
				$captured = $this->scanFirstVariable($value);

				return [
					'kind' => VarTypePlacement::ANCHOR_CAPTURE,
					'line' => $token->line,
					'bound' => $captured === null ? null : [$captured],
					'label' => $label,
				];
			case 'foreach':
				return [
					'kind' => VarTypePlacement::ANCHOR_FOREACH,
					'line' => $token->line,
					'bound' => $this->scanForeachBindings($value),
					'label' => $label,
				];
			case 'php':
			case 'do':
				// Anything this scanner cannot reduce to `$name = ...` binds an unresolvable set
				// (a list assignment, a property write, a bare call): still an anchor, never
				// name-checked, so an unparsed body can never manufacture a name mismatch.
				$assigned = $this->scanSimpleAssignTarget($value);

				return [
					'kind' => VarTypePlacement::ANCHOR_ASSIGN,
					'line' => $token->line,
					'bound' => $assigned === null ? null : [$assigned],
					'label' => $label,
				];
		}

		return ['kind' => null, 'line' => null, 'bound' => null, 'label' => $label];
	}

	// n:foreach/n:inner-foreach compile to the same Foreach_ a {foreach} tag does, so an element
	// carrying one is an anchor for a {varType} written directly above it. Attributes are their own
	// token kind between the element's HTML_TAG_BEGIN and its HTML_TAG_END.

	/**
	 * @param array<int, Token> $tokens
	 */
	private function findForeachAttribute(array $tokens, int $index): ?string
	{
		$count = count($tokens);
		for ($i = $index + 1; $i < $count; $i++) {
			$token = $tokens[$i];
			if ($token->type === Token::HTML_TAG_END || $token->type === Token::HTML_TAG_BEGIN) {
				return null;
			}

			if (
				$token->type === Token::HTML_ATTRIBUTE_BEGIN
				&& in_array($token->name, self::FOREACH_ATTRIBUTES, true)
				&& $token->value !== null
			) {
				return $token->value;
			}
		}

		return null;
	}

	/**
	 * @return list<string>
	 */
	private function scanForeachBindings(string $value): array
	{
		$tokens = new MacroTokens($value);

		$iterateeTokens = 0;
		$iterateeVariable = null;
		$bound = [];
		$seenAs = false;
		while ($tokens->nextToken() !== null) {
			if (!$seenAs && $tokens->depth === 0 && $tokens->isCurrent('as')) {
				$seenAs = true;

				continue;
			}

			if (!$seenAs) {
				if (!$tokens->isCurrent(...MacroTokens::SIGNIFICANT)) {
					continue;
				}

				$iterateeTokens++;
				if ($tokens->isCurrent(MacroTokens::T_VARIABLE)) {
					$iterateeVariable = ltrim((string) $tokens->currentValue(), '$');
				}

				continue;
			}

			// Key and value are collected into one flat set: every name the loop binds is equally
			// valid to declare, and the message lists them all anyway.
			if ($tokens->isCurrent(MacroTokens::T_VARIABLE)) {
				$bound[] = ltrim((string) $tokens->currentValue(), '$');
			}
		}

		// Only a bare `{foreach $items as ...}` names its iterable; `$this->rows` or a call is a
		// whole expression, matching WrongVariableNameInVarTagRule's own
		// `$iterateeExpr instanceof Variable` gate rather than merely containing a variable.
		if ($iterateeTokens === 1 && $iterateeVariable !== null) {
			array_unshift($bound, $iterateeVariable);
		}

		return $bound;
	}

	private function scanFirstVariable(string $value): ?string
	{
		$tokens = new MacroTokens($value);
		$variable = $tokens->nextValue(MacroTokens::T_VARIABLE);

		return $variable === null ? null : ltrim($variable, '$');
	}

	private function scanSimpleAssignTarget(string $value): ?string
	{
		$tokens = new MacroTokens($value);
		if (!$tokens->isNext(MacroTokens::T_VARIABLE)) {
			return null;
		}

		$variable = (string) $tokens->nextValue(MacroTokens::T_VARIABLE);
		if ($tokens->nextToken() === null || !$tokens->isCurrent('=')) {
			return null;
		}

		return ltrim($variable, '$');
	}

	/**
	 * @return array<int, array{string, string|null, int}>
	 */
	private function scanVarDeclarations(string $value, int $line): array
	{
		$entries = [];
		$tokens = new MacroTokens($value);

		$expectingName = true;
		$hasType = false;
		$type = null;
		$name = null;

		while ($tokens->nextToken() !== null) {
			if (
				$expectingName
				&& $tokens->isCurrent(MacroTokens::T_SYMBOL)
				&& ($tokens->isNext(',', '=>', '=') || !$tokens->isNext(...MacroTokens::SIGNIFICANT))
			) {
				// deprecated bare variable name (no leading $) - always untyped, so it only ever
				// contributes to the anchor's BOUND-variable set, never to typedVars/typedDefaults
				$name = $tokens->currentValue();
				$expectingName = false;
			} elseif ($expectingName && !$hasType && $tokens->isCurrent(MacroTokens::T_SYMBOL, '?', 'null', '\\')) {
				$type = $tokens->currentValue();
				$tokens->nextToken();
				while (($piece = $tokens->nextValue(MacroTokens::T_SYMBOL, '\\', '|', '[', ']', 'null')) !== null) {
					$type .= $piece;
				}

				$hasType = true;

				continue;
			} elseif ($expectingName && $tokens->isCurrent(MacroTokens::T_SYMBOL, MacroTokens::T_VARIABLE)) {
				$name = ltrim((string) $tokens->currentValue(), '$');
				$expectingName = false;
			} elseif ($tokens->isCurrent(',', ';') && $tokens->depth === 0) {
				if ($name !== null) {
					$entries[] = [$name, $type, $line];
				}

				$name = null;
				$type = null;
				$expectingName = true;
				$hasType = false;
			}
		}

		if ($name !== null) {
			$entries[] = [$name, $type, $line];
		}

		return $entries;
	}

	/**
	 * @return array<int, array{string|null, string, string|null, int}>
	 */
	private function scanParameters(string $value, int $line): array
	{
		$tokens = new MacroTokens($value);
		$params = [];

		while ($tokens->isNext(...MacroTokens::SIGNIFICANT)) {
			$type = $this->scanTypePrefix($tokens);
			$name = ltrim($tokens->consumeValue(MacroTokens::T_VARIABLE), '$');
			$default = $tokens->nextToken('=') !== null
				? trim($tokens->joinUntilSameDepth(','))
				: null;

			$params[] = [$type, $name, $default, $line];

			if ($tokens->nextToken(',') === null) {
				break;
			}
		}

		return $params;
	}

	/**
	 * @return array{string, array<int, array{string|null, string}>, array<string, true>}
	 */
	private function scanDefine(string $value): array
	{
		$tokens = new MacroTokens($value);
		$fetched = $tokens->fetchWordWithModifier('local');
		$name = $fetched === null ? '' : ltrim(trim($fetched[0]), '#');

		$params = [];
		$defaults = [];
		while ($tokens->isNext(...MacroTokens::SIGNIFICANT)) {
			$type = $this->scanTypePrefix($tokens);
			$paramName = ltrim($tokens->consumeValue(MacroTokens::T_VARIABLE), '$');
			if ($tokens->nextToken('=') !== null) {
				$tokens->joinUntilSameDepth(',');
				$defaults[$paramName] = true;
			}

			$params[] = [$type, $paramName];

			if ($tokens->nextToken(',') === null) {
				break;
			}
		}

		return [$name, $params, $defaults];
	}

	// Drops every open own-method body the just-closed tag ended, against the depth the shared
	// BlockBodyTracker has already decremented.

	/**
	 * @param list<int> $bodyDepths
	 * @return list<int>
	 */
	private function popClosedBodies(array $bodyDepths, int $depth): array
	{
		while ($bodyDepths !== [] && end($bodyDepths) > $depth) {
			array_pop($bodyDepths);
		}

		return $bodyDepths;
	}

	// BlockMacros::isDynamic() verbatim - a name Latte cannot resolve at compile time.
	private function isDynamicName(string $name): bool
	{
		return strpos($name, '$') !== false || strpos($name, ' ') !== false;
	}

	private function scanTypePrefix(MacroTokens $tokens): ?string
	{
		$type = $tokens->nextValue(MacroTokens::T_SYMBOL, '?', 'null', '\\');
		if ($type === null) {
			return null;
		}

		while (($piece = $tokens->nextValue(MacroTokens::T_SYMBOL, '\\', '|', '[', ']', 'null')) !== null) {
			$type .= $piece;
		}

		return $type;
	}

	private function empty(): Declarations
	{
		return new Declarations(null, null, [], [], [], [], [], null, [], [], []);
	}

}
