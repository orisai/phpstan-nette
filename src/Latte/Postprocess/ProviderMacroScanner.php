<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess;

use OriPhpstan\Nette\Latte\Runtime\Helpers;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use function in_array;

// Finds the compiled sites of every macro that dereferences a control-gated provider
// ($this->global->uiControl/uiPresenter, and $this->global->snippetDriver - built from the
// control's own snippetBridge, see ProviderAvailabilityChecker's own class doc) - the SAME
// PropertyFetch-off-$this->global shape UiMacroEliminator/FormsMacroEliminator/
// BlockDispatchEliminator already key their own matching on (GlobalPropertyFetchMatcher), read
// here for detection rather than elimination.
//
// TWO PASSES, because the eliminators themselves resolve one ambiguity and erase another:
//   - {form x} (uiControl["x"]) and {form $var} (is_object($var) ? $var : uiControl[$var]) both
//     contain a raw uiControl reference, but only the FIRST needs it unconditionally - $var may
//     always be an already-resolved object, which never touches uiControl at runtime.
//     FormsMacroEliminator already tells the two apart (Helpers::form() vs Helpers::formObject()),
//     so {form} is read from its POST-elimination output instead of re-deriving that split here.
//   - {control name} and {control $var} (is_object($var) ? $var : uiControl->getComponent($var))
//     collapse into the identical Helpers::component() either way - UiMacroEliminator itself never
//     preserves that distinction downstream, so neither does this: scanRaw() reports every
//     uiControl->getComponent() call, static or dynamic name alike, matching the pipeline's own
//     established precision for this one macro.
// Every other construct (link/plink/ifCurrent/snippet) has no such ambiguity at all, so scanRaw()
// reads them straight off the RAW, pre-elimination AST - BlockDispatchEliminator's own snippetDriver
// enter()/leave() shell is DROPPED entirely (only the try body survives), so a snippet site has no
// post-elimination trace to read at all.
final class ProviderMacroScanner
{

	public const PROVIDER_UI_CONTROL = 'uiControl';

	public const PROVIDER_UI_PRESENTER = 'uiPresenter';

	public const PROVIDER_SNIPPET_DRIVER = 'snippetDriver';

	public const MACRO_CONTROL = '{control}';

	public const MACRO_LINK = '{link}/n:href';

	public const MACRO_PLINK = '{plink}';

	public const MACRO_IF_CURRENT = '{ifCurrent}';

	public const MACRO_FORM = '{form}';

	public const MACRO_SNIPPET = '{snippet}/{snippetArea}';

	/**
	 * @param array<Stmt> $stmts
	 * @return list<array{macro: string, provider: string, line: int}>
	 */
	public function scanRaw(array $stmts): array
	{
		$sites = [];

		foreach ((new NodeFinder())->findInstanceOf($stmts, MethodCall::class) as $call) {
			$classified = $this->classifyMethodCall($call);
			if ($classified !== null) {
				[$macro, $provider] = $classified;
				$sites[] = ['macro' => $macro, 'provider' => $provider, 'line' => $call->getStartLine()];
			}
		}

		return $sites;
	}

	/**
	 * @param array<Stmt> $stmts
	 * @return list<array{macro: string, provider: string, line: int}>
	 */
	public function scanForms(array $stmts): array
	{
		$sites = [];

		foreach ((new NodeFinder())->findInstanceOf($stmts, StaticCall::class) as $call) {
			if (
				!$call->class instanceof Name
				|| $call->class->toString() !== Helpers::class
				|| !$call->name instanceof Identifier
				|| $call->name->toString() !== 'form'
			) {
				continue;
			}

			$sites[] = [
				'macro' => self::MACRO_FORM,
				'provider' => self::PROVIDER_UI_CONTROL,
				'line' => $this->lineOf($call),
			];
		}

		return $sites;
	}

	/**
	 * @return array{string, string}|null
	 */
	private function classifyMethodCall(MethodCall $node): ?array
	{
		if (!$node->name instanceof Identifier) {
			return null;
		}

		$methodName = $node->name->toString();

		if (
			$methodName === 'getComponent'
			&& GlobalPropertyFetchMatcher::matches($node->var, self::PROVIDER_UI_CONTROL)
		) {
			return [self::MACRO_CONTROL, self::PROVIDER_UI_CONTROL];
		}

		if ($methodName === 'link' && GlobalPropertyFetchMatcher::matches($node->var, self::PROVIDER_UI_CONTROL)) {
			return [self::MACRO_LINK, self::PROVIDER_UI_CONTROL];
		}

		if ($methodName === 'link' && GlobalPropertyFetchMatcher::matches($node->var, self::PROVIDER_UI_PRESENTER)) {
			return [self::MACRO_PLINK, self::PROVIDER_UI_PRESENTER];
		}

		if (
			in_array($methodName, ['isLinkCurrent', 'getLastCreatedRequestFlag'], true)
			&& GlobalPropertyFetchMatcher::matches($node->var, self::PROVIDER_UI_PRESENTER)
		) {
			return [self::MACRO_IF_CURRENT, self::PROVIDER_UI_PRESENTER];
		}

		// 'enter' alone: every {snippet}/{snippetArea} shape (static, dynamic, attribute-form
		// n:snippet) opens with exactly one snippetDriver->enter() call, so this is a single,
		// non-duplicating anchor per tag - unlike leave()/getHtmlId(), which either coincide with
		// it on separate, redundant statements or are absent for the plain block-form tag.
		if ($methodName === 'enter' && GlobalPropertyFetchMatcher::matches($node->var, self::PROVIDER_SNIPPET_DRIVER)) {
			return [self::MACRO_SNIPPET, self::PROVIDER_SNIPPET_DRIVER];
		}

		return null;
	}

	// A rebuilt Helpers:: call (FormsMacroEliminator's own StaticCall construction) carries no
	// startLine of its own - only its retained argument expression (the ORIGINAL {form x} name
	// literal/expression) does, reused as-is rather than rebuilt.
	private function lineOf(StaticCall $call): int
	{
		$line = $call->getStartLine();
		if ($line > 0) {
			return $line;
		}

		$firstArg = $call->args[0] ?? null;

		return $firstArg instanceof Arg ? $firstArg->value->getStartLine() : $line;
	}

}
