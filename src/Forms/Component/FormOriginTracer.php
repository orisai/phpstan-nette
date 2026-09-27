<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Component;

use OriPhpstan\Nette\Forms\Graph\ClosureScope;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use function array_key_first;
use function array_unshift;
use function count;
use function implode;
use function in_array;
use function is_string;
use function lcfirst;
use function spl_object_id;
use function strncmp;
use function substr;

final class FormOriginTracer
{

	private const HANDLER_PROPERTIES = ['onSuccess', 'onValidate', 'onSubmit', 'onError', 'onAnchor', 'onClick'];

	/** @var array<string, string|false> */
	private array $memo = [];

	/** @var array<string, string|false> */
	private array $chainMemo = [];

	public function originComponent(Class_ $ownerClass, ClassMethod $enclosing, string $paramName): ?string
	{
		$declares = false;
		foreach ($enclosing->params as $param) {
			if ($param->var instanceof Variable && $param->var->name === $paramName) {
				$declares = true;

				break;
			}
		}

		if (!$declares) {
			return null;
		}

		$method = $enclosing->name->toString();
		$key = (isset($ownerClass->namespacedName) ? $ownerClass->namespacedName->toString() : '?')
			. '::' . $method . '#' . $paramName;
		if (!isset($this->memo[$key])) {
			$this->memo[$key] = $this->compute($ownerClass, $method) ?? false;
		}

		return $this->memo[$key] === false ? null : $this->memo[$key];
	}

	private function compute(Class_ $ownerClass, string $method): ?string
	{
		$matches = [];
		foreach ($ownerClass->getMethods() as $factory) {
			$name = $factory->name->toString();
			if (strncmp($name, 'createComponent', 15) !== 0) {
				continue;
			}

			// Only the returned form is the component; a handler bound on a different local form
			// built in the same factory does not make its method's parameter that component.
			$returnedVar = $this->returnedVarName($factory);
			if ($returnedVar === null) {
				continue;
			}

			$closureInnerIds = ClosureScope::innerNodeIds($factory->getStmts() ?? []);
			foreach ((new NodeFinder())->findInstanceOf($factory->getStmts() ?? [], Assign::class) as $assign) {
				if (isset($closureInnerIds[spl_object_id($assign)])) {
					continue;
				}

				if ($this->handlerSinkReceiver($assign->var) !== $returnedVar) {
					continue;
				}

				if ($this->targetsMethod($assign->expr, $method)) {
					$matches[lcfirst((string) substr($name, 15))] = true;
				}
			}
		}

		return count($matches) === 1 ? array_key_first($matches) : null;
	}

	private function handlerSinkReceiver(Node $var): ?string
	{
		if ($var instanceof ArrayDimFetch && $var->dim === null) {
			$var = $var->var;
		}

		if (
			!$var instanceof PropertyFetch
			|| !$var->name instanceof Node\Identifier
			|| !in_array($var->name->toString(), self::HANDLER_PROPERTIES, true)
		) {
			return null;
		}

		return $var->var instanceof Variable && is_string($var->var->name) ? $var->var->name : null;
	}

	private function targetsMethod(Node $expr, string $method): bool
	{
		if ($expr instanceof Array_ && count($expr->items) === 2) {
			$callee = $expr->items[0]->value;
			$target = $expr->items[1]->value;

			return $callee instanceof Variable
				&& $callee->name === 'this'
				&& $target instanceof String_
				&& $target->value === $method;
		}

		if ($expr instanceof Closure || $expr instanceof ArrowFunction) {
			$body = $expr instanceof ArrowFunction ? [$expr->expr] : $expr->getStmts();
			foreach ((new NodeFinder())->findInstanceOf($body, MethodCall::class) as $call) {
				if (
					$call->var instanceof Variable
					&& $call->var->name === 'this'
					&& $call->name instanceof Node\Identifier
					&& $call->name->toString() === $method
				) {
					return true;
				}
			}
		}

		return false;
	}

	public function originChainKey(Class_ $ownerClass, ClassMethod $enclosing, string $paramName): ?string
	{
		$idx = null;
		$i = 0;
		foreach ($enclosing->params as $param) {
			if ($param->var instanceof Variable && $param->var->name === $paramName) {
				$idx = $i;

				break;
			}

			$i++;
		}

		if ($idx === null) {
			return null;
		}

		$methodName = $enclosing->name->toString();
		$memoKey = (isset($ownerClass->namespacedName) ? $ownerClass->namespacedName->toString() : '?')
			. '::' . $methodName . '#chain#' . $paramName;
		if (!isset($this->chainMemo[$memoKey])) {
			$this->chainMemo[$memoKey] = $this->computeChain($ownerClass, $methodName, $idx) ?? false;
		}

		return $this->chainMemo[$memoKey] === false ? null : $this->chainMemo[$memoKey];
	}

	private function computeChain(Class_ $ownerClass, string $methodName, int $idx): ?string
	{
		$canonical = null;
		$saw = false;
		foreach ($ownerClass->getMethods() as $factory) {
			$fname = $factory->name->toString();
			if (strncmp($fname, 'createComponent', 15) !== 0) {
				continue;
			}

			$formVar = $this->returnedVarName($factory);
			if ($formVar === null) {
				continue;
			}

			$component = lcfirst((string) substr($fname, 15));
			foreach ((new NodeFinder())->findInstanceOf($factory->getStmts() ?? [], MethodCall::class) as $call) {
				if (
					!$call->var instanceof Variable
					|| $call->var->name !== 'this'
					|| !$call->name instanceof Node\Identifier
					|| $call->name->toString() !== $methodName
				) {
					continue;
				}

				// A first-class callable lets the method run later with arbitrary arguments,
				// so no canonical origin chain can be claimed.
				if ($call->isFirstClassCallable()) {
					return null;
				}

				$saw = true;
				$args = $call->getArgs();
				if (!isset($args[$idx])) {
					return null;
				}

				$prefix = $this->argPrefix($args[$idx]->value, $formVar);
				if ($prefix === null) {
					return null;
				}

				$canon = $prefix === [] ? $component : $component . '|' . implode('|', $prefix);
				if ($canonical === null) {
					$canonical = $canon;
				} elseif ($canonical !== $canon) {
					return null;
				}
			}
		}

		return $saw ? $canonical : null;
	}

	private function returnedVarName(ClassMethod $factory): ?string
	{
		$stmts = $factory->getStmts() ?? [];
		$closureInnerIds = ClosureScope::innerNodeIds($stmts);
		foreach ((new NodeFinder())->findInstanceOf($stmts, Return_::class) as $ret) {
			if (isset($closureInnerIds[spl_object_id($ret)])) {
				continue;
			}

			if ($ret->expr instanceof Variable && is_string($ret->expr->name)) {
				return $ret->expr->name;
			}
		}

		return null;
	}

	/**
	 * @return list<string>|null
	 */
	private function argPrefix(Node $arg, string $formVar): ?array
	{
		$prefix = [];
		$n = $arg;
		while ($n instanceof ArrayDimFetch) {
			if (!$n->dim instanceof String_) {
				return null;
			}

			array_unshift($prefix, $n->dim->value);
			$n = $n->var;
		}

		return $n instanceof Variable && $n->name === $formVar ? $prefix : null;
	}

}
