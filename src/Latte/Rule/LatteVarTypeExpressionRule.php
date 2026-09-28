<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Rule;

use Nette\IOException;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Latte\Declarations\DeclarationScanner;
use OriPhpstan\Nette\Latte\Declarations\VarTypePlacement;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PHPStan\Analyser\Scope;
use PHPStan\Node\Expr\NativeTypeExpr;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use Throwable;
use function array_key_exists;
use function count;
use function is_string;
use function preg_match;
use function strncmp;
use function substr_compare;

// Third {varType} axis (see VarTypePlacementChecker's own note for the other two): the declared
// type against the REAL inferred type of the expression the anchor assigns. Registered on Stmt the
// same way WrongVariableNameInVarTagRule is - the engine then hands over its own local Scope at
// exactly the point in the compiled template where the assignment happens, which is the only way to
// get that type.
//
// Anchors are resolved from the .latte TOKEN stream (DeclarationScanner), never from the compiled
// AST: the pipeline rewrites the very shapes an AST matcher would key on, and its own mid-file
// {varType} injection adds an assignment at the tag's own line. Matching by (anchor line, variable
// name) against the token-level answer is stable across every pipeline stage.
/**
 * @implements Rule<Node\Stmt>
 */
final class LatteVarTypeExpressionRule implements Rule
{

	// DeclarationInjector's own carrier for a declared type - an assignment READING one is the
	// injection itself, never a template author's expression.
	private const INJECTED_CARRIER_PATTERN = '~^prop_\d+_~';

	private DeclarationScanner $scanner;

	private TypeStringResolver $typeStringResolver;

	private VarTypeExpressionChecker $checker;

	/** @var array<string, list<VarTypePlacement>> */
	private array $placementsByFile = [];

	// Round-robin cursor, keyed by traversal ("$file\0$function") plus "$line:$kind:$name", for the
	// rare case where more than one {varType} for the SAME variable shares one anchor line (each
	// immediately re-declaring it with its own {var}/{default}/{foreach} right there) - see
	// matchPlacement()'s own note for why the traversal, not the file, bounds it.
	/** @var array<string, int> */
	private array $matchCursor = [];

	public function __construct(
		ConfigurationGuard $guard,
		DeclarationScanner $scanner,
		TypeStringResolver $typeStringResolver,
		VarTypeExpressionChecker $checker
	)
	{
		$guard->validate();
		$this->scanner = $scanner;
		$this->typeStringResolver = $typeStringResolver;
		$this->checker = $checker;
	}

	public function getNodeType(): string
	{
		return Node\Stmt::class;
	}

	/**
	 * @return list<IdentifierRuleError>
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		if (!$node instanceof Expression && !$node instanceof Foreach_) {
			return [];
		}

		if (substr_compare($scope->getFile(), '.latte', -6) !== 0) {
			return [];
		}

		$placements = $this->placementsFor($scope->getFile());
		if ($placements === []) {
			return [];
		}

		return $node instanceof Foreach_
			? $this->processForeach($node, $scope, $placements)
			: $this->processExpression($node, $scope, $placements);
	}

	/**
	 * @param list<VarTypePlacement> $placements
	 * @return list<IdentifierRuleError>
	 */
	private function processExpression(Expression $node, Scope $scope, array $placements): array
	{
		$expr = $node->expr;
		if (!$expr instanceof Assign && !$expr instanceof AssignRef && !$expr instanceof AssignOp) {
			return [];
		}

		if (!$expr->var instanceof Variable || !is_string($expr->var->name)) {
			return [];
		}

		if ($this->isInjectedCarrier($expr->expr)) {
			return [];
		}

		$placement = $this->matchPlacement(
			$placements,
			$scope,
			$node->getStartLine(),
			VarTypePlacement::ANCHOR_ASSIGN,
			$expr->var->name,
		);
		if ($placement === null) {
			return [];
		}

		return $this->compare($scope, $placement, $expr->expr);
	}

	/**
	 * @param list<VarTypePlacement> $placements
	 * @return list<IdentifierRuleError>
	 */
	private function processForeach(Foreach_ $node, Scope $scope, array $placements): array
	{
		$line = $node->getStartLine();
		$errors = [];

		if ($node->expr instanceof Variable && is_string($node->expr->name)) {
			$placement = $this->matchPlacement(
				$placements,
				$scope,
				$line,
				VarTypePlacement::ANCHOR_FOREACH,
				$node->expr->name,
			);
			if ($placement !== null) {
				foreach ($this->compare($scope, $placement, $node->expr) as $error) {
					$errors[] = $error;
				}
			}
		}

		if ($node->keyVar instanceof Variable && is_string($node->keyVar->name)) {
			$placement = $this->matchPlacement(
				$placements,
				$scope,
				$line,
				VarTypePlacement::ANCHOR_FOREACH,
				$node->keyVar->name,
			);
			if ($placement !== null) {
				foreach ($this->compare($scope, $placement, new NativeTypeExpr(
					$scope->getIterableKeyType($scope->getScopeType($node->expr)),
					$scope->getIterableKeyType($scope->getScopeNativeType($node->expr)),
				)) as $error) {
					$errors[] = $error;
				}
			}
		}

		if ($node->valueVar instanceof Variable && is_string($node->valueVar->name)) {
			$placement = $this->matchPlacement(
				$placements,
				$scope,
				$line,
				VarTypePlacement::ANCHOR_FOREACH,
				$node->valueVar->name,
			);
			if ($placement !== null) {
				foreach ($this->compare($scope, $placement, new NativeTypeExpr(
					$scope->getIterableValueType($scope->getScopeType($node->expr)),
					$scope->getIterableValueType($scope->getScopeNativeType($node->expr)),
				)) as $error) {
					$errors[] = $error;
				}
			}
		}

		return $errors;
	}

	/**
	 * @return list<IdentifierRuleError>
	 */
	private function compare(Scope $scope, VarTypePlacement $placement, Expr $expr): array
	{
		try {
			$declared = $this->typeStringResolver->resolve($placement->getType());
		} catch (Throwable $e) {
			// orisaiNette.latte.unknownType / the compiler's own parse error already cover a malformed type
			// string; DeclarationConsistencyChecker degrades on the same input the same way.
			return [];
		}

		return $this->checker->check($scope, $placement->getName(), $expr, $declared, $placement->getLine());
	}

	// Ordinarily (line, kind, name) identifies exactly one placement. It identifies SEVERAL only
	// when more than one {varType} for the same variable shares one anchor line - either each one
	// immediately re-declaring it with its own {var}/{default}/{foreach} right there
	// (`{varType int $x}{var $x = 1}{varType string $x}{var $x = 'a'}`), or a whole RUN of them
	// anchored on one single statement (`{varType int $x}{varType string $x}{var $x = 1}`). In the
	// first shape the second statement must pair with the SECOND declaration, not always the first,
	// or a real conflict on it is never checked at all - hence a cursor advancing in scan order.
	//
	// The cursor is keyed per TRAVERSAL, not per file: the compiled class holds one latteMain_ctx{i}
	// clone per distinct include context plus its own block methods, and each of those re-visits the
	// very same statement sequence. A file-wide cursor kept advancing across those re-visits, so with
	// C candidates and V matching statements per traversal the candidate a given statement paired
	// with was a function of (V * clone index) mod C - i.e. of how many OTHER templates happened to
	// include this one. Where V == C that arithmetic silently self-corrected; where it did not
	// (the run shape above: C = 2, V = 1) a template reported different {varType} findings purely
	// because an unrelated file started including it. Restarting the cursor on every traversal makes
	// the pairing a pure function of the template's own bytes.
	private function traversalKey(Scope $scope): string
	{
		$function = $scope->getFunction();

		return $scope->getFile() . "\0" . ($function !== null ? $function->getName() : '');
	}

	// The traversal-keyed cursor above bounds how a statement CYCLES through its candidates; it
	// cannot say which candidates a traversal is entitled to in the first place, and (line, kind,
	// name) is not unique across compiled methods. `{varType int $x}{var $x = 1}{block foo}
	// {varType string $x}{var $x = 'a'}{/block}` on ONE line puts a second assignment to $x on the
	// declaration's own line inside blockFoo(), where the {varType} it belongs to is the block's
	// own input contract and therefore not a placement at all - so the block's assignment matched
	// the MAIN body's declaration and reported its type as a conflict.
	//
	// Latte's compiled class holds exactly main(), prepare() and one 'block'-prefixed method per
	// named {block}/{define}/{snippet}/{snippetArea} (Compiler::buildClassBody plus
	// BlockMacros::generateMethodName are the only addMethod() callers); DeclarationInjector renames
	// the first two to latteMain/latteMain_ctx{i} and lattePrepare. A method name starting with
	// "block" is therefore a block traversal and nothing else is.
	private function isBlockTraversal(Scope $scope): bool
	{
		$function = $scope->getFunction();

		return $function !== null && strncmp($function->getName(), 'block', 5) === 0;
	}

	/**
	 * @param list<VarTypePlacement> $placements
	 * @param VarTypePlacement::ANCHOR_* $kind
	 */
	private function matchPlacement(
		array $placements,
		Scope $scope,
		int $line,
		string $kind,
		string $name
	): ?VarTypePlacement
	{
		$isBlockTraversal = $this->isBlockTraversal($scope);

		$candidates = [];
		foreach ($placements as $placement) {
			if (
				$placement->getAnchorKind() === $kind
				&& $placement->getAnchorLine() === $line
				&& $placement->getName() === $name
				&& $placement->isInsideBlockBody() === $isBlockTraversal
			) {
				$candidates[] = $placement;
			}
		}

		if ($candidates === []) {
			return null;
		}

		$key = $this->traversalKey($scope) . ':' . $line . ':' . $kind . ':' . $name;
		$index = ($this->matchCursor[$key] ?? 0) % count($candidates);
		$this->matchCursor[$key] = $index + 1;

		return $candidates[$index];
	}

	private function isInjectedCarrier(Expr $expr): bool
	{
		return $expr instanceof StaticPropertyFetch
			&& $expr->name instanceof Node\Identifier
			&& preg_match(self::INJECTED_CARRIER_PATTERN, $expr->name->toString()) === 1;
	}

	/**
	 * @return list<VarTypePlacement>
	 */
	private function placementsFor(string $file): array
	{
		if (array_key_exists($file, $this->placementsByFile)) {
			return $this->placementsByFile[$file];
		}

		try {
			$source = FileSystem::read($file);
		} catch (IOException $e) {
			// A file that vanished mid-run must not take the analysis down - LatteRoutingParser's
			// own precedent for the identical read.
			return $this->placementsByFile[$file] = [];
		}

		return $this->placementsByFile[$file] = $this->scanner->scan($source)->getVarTypePlacements();
	}

}
