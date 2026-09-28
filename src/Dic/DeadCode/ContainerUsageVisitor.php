<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Dic\DeadCode;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeVisitorAbstract;
use function array_pop;
use function is_string;
use function ltrim;
use function strtolower;

final class ContainerUsageVisitor extends NodeVisitorAbstract
{

	/** @var array<string, array<string, true>> */
	private array $usages = [];

	/** @var array<string, string> */
	private array $localTypes = [];

	/** @var list<array<string, string>> */
	private array $scopeStack = [];

	public function enterNode(Node $node): ?Node
	{
		if ($node instanceof ClassMethod) {
			$this->pushScope([]);

			return null;
		}

		if ($node instanceof Closure) {
			$this->pushScope($this->closureScope($node));

			return null;
		}

		if ($node instanceof ArrowFunction) {
			$this->pushScope($this->withoutParams($this->localTypes, $node->params));

			return null;
		}

		if ($node instanceof Assign) {
			$this->recordLocalTypeBinding($node);

			return null;
		}

		if ($node instanceof New_) {
			$this->recordConstruction($node);

			return null;
		}

		if ($node instanceof MethodCall) {
			$this->recordMethodCall($node);

			return null;
		}

		if ($node instanceof StaticCall) {
			$this->recordStaticCall($node);

			return null;
		}

		return null;
	}

	public function leaveNode(Node $node): ?Node
	{
		if ($node instanceof ClassMethod || $node instanceof Closure || $node instanceof ArrowFunction) {
			$this->popScope();
		}

		return null;
	}

	/**
	 * @return array<string, array<string, true>>
	 */
	public function getUsages(): array
	{
		return $this->usages;
	}

	/**
	 * @param array<string, string> $scope
	 */
	private function pushScope(array $scope): void
	{
		$this->scopeStack[] = $this->localTypes;
		$this->localTypes = $scope;
	}

	private function popScope(): void
	{
		$this->localTypes = array_pop($this->scopeStack) ?? [];
	}

	/**
	 * A closure's body must not inherit the enclosing scope's bindings implicitly — only
	 * variables named in its use() clause carry their outer type in.
	 *
	 * @return array<string, string>
	 */
	private function closureScope(Closure $closure): array
	{
		$useNames = [];

		foreach ($closure->uses as $use) {
			if (is_string($use->var->name)) {
				$useNames[$use->var->name] = true;
			}
		}

		$scope = [];

		foreach ($this->localTypes as $name => $type) {
			if (isset($useNames[$name])) {
				$scope[$name] = $type;
			}
		}

		return $this->withoutParams($scope, $closure->params);
	}

	/**
	 * @param array<string, string> $scope
	 * @param array<Param> $params
	 * @return array<string, string>
	 */
	private function withoutParams(array $scope, array $params): array
	{
		foreach ($params as $param) {
			if ($param->var instanceof Variable && is_string($param->var->name)) {
				unset($scope[$param->var->name]);
			}
		}

		return $scope;
	}

	private function recordConstruction(New_ $new): void
	{
		if (!$new->class instanceof Name) {
			return;
		}

		$this->markUsed($this->className($new->class), '__construct');
	}

	private function recordLocalTypeBinding(Assign $assign): void
	{
		if (
			!$assign->var instanceof Variable
			|| !is_string($assign->var->name)
			|| !$assign->expr instanceof New_
			|| !$assign->expr->class instanceof Name
		) {
			return;
		}

		$this->localTypes[$assign->var->name] = $this->className($assign->expr->class);
	}

	private function recordMethodCall(MethodCall $call): void
	{
		if (
			!$call->var instanceof Variable
			|| !is_string($call->var->name)
			|| !$call->name instanceof Identifier
		) {
			return;
		}

		$className = $this->localTypes[$call->var->name] ?? null;

		if ($className === null) {
			return;
		}

		$this->markUsed($className, $call->name->toString());
	}

	private function recordStaticCall(StaticCall $call): void
	{
		if (
			!$call->class instanceof Name
			|| $call->class->isSpecialClassName()
			|| !$call->name instanceof Identifier
		) {
			return;
		}

		$this->markUsed($this->className($call->class), $call->name->toString());
	}

	private function className(Name $name): string
	{
		return ltrim($name->toString(), '\\');
	}

	private function markUsed(string $className, string $methodName): void
	{
		$this->usages[$className][strtolower($methodName)] = true;
	}

}
