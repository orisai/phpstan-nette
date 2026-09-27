<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Graph;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitorAbstract;
use function assert;

/**
 * A form held in `$this->prop`, said in the only vocabulary the shape walk speaks.
 *
 * The walk tracks ONE VARIABLE NAME: every mutation it can attribute is spelled `$name->add*()`,
 * `$name['x'] = …` or `unset($name['x'])` (FormShapeAnalyzer::targetsTrackedVariable), and a
 * property-held form matches none of those spellings. It is not modelled imprecisely — it is unspoken.
 * So rewrite() hands the walk a CLONE of a method body in which every `$this->prop` reads as a
 * variable of a name no PHP source can bind, followed by a return of it. The existing fold then
 * applies verbatim, rebind markers, factory/constructor compensations and all, and no second walker
 * exists to drift away from the first.
 *
 * The clone is not optional: the analysis AST of a file is shared with every other consumer of it.
 *
 * The scans answer what the rewrite cannot. bindsProperty() names the fold's sources.
 * hasUnattributableAccess() names the writes the fold structurally cannot follow — a receiver other
 * than `$this` (legal for a private property between two instances of the declaring class), a
 * dynamic property name, a by-ref or compound binding, or a return that hands the form to a caller —
 * each of which must OPEN the resulting shape, because a shape silently missing a member is the
 * false "this field does not exist" this whole channel is built to avoid.
 */
final class PropertyFormAccess
{

	private function __construct()
	{
	}

	/**
	 * The variable name the property is normalised into. Deliberately unspellable in PHP source, so
	 * it can never collide with a real binding in the rewritten body, and deliberately not printable
	 * as `$this->prop` either: PHPStan's Scope caches types by PRINTED expression, and a synthetic
	 * variable printing like a real property fetch would inherit whatever type the borrowed (often
	 * foreign) scope happens to hold for that spelling.
	 */
	public static function variableName(string $propertyName): string
	{
		return '@property:' . $propertyName;
	}

	public static function namesProperty(Node $node, string $propertyName): bool
	{
		return ($node instanceof PropertyFetch || $node instanceof NullsafePropertyFetch)
			&& $node->var instanceof Variable
			&& $node->var->name === 'this'
			&& $node->name instanceof Identifier
			&& $node->name->toString() === $propertyName;
	}

	public static function mentionsProperty(ClassMethod $method, string $propertyName): bool
	{
		return self::find($method, static fn (Node $node): bool => self::namesProperty($node, $propertyName));
	}

	/**
	 * Whether the method binds the property with a plain assignment — the one binding family the fold
	 * can read, since the rewritten `$name = …` is exactly what its rebind kinds and its factory /
	 * constructor compensations are written against. Every other binding family reaches the caller
	 * through hasUnattributableAccess() instead.
	 */
	public static function bindsProperty(ClassMethod $method, string $propertyName): bool
	{
		return self::find(
			$method,
			static fn (Node $node): bool => $node instanceof Assign && self::namesProperty($node->var, $propertyName),
		);
	}

	public static function hasUnattributableAccess(ClassMethod $method, string $propertyName): bool
	{
		return self::find($method, static function (Node $node) use ($propertyName): bool {
			if ($node instanceof PropertyFetch || $node instanceof NullsafePropertyFetch) {
				$isThis = $node->var instanceof Variable && $node->var->name === 'this';

				// $other->prop reaches the same class's property from another instance; $this->$name
				// may or may not be this property, and no per-class fold can tell which.
				return $node->name instanceof Identifier
					? !$isThis && $node->name->toString() === $propertyName
					: $isThis;
			}

			if ($node instanceof AssignRef) {
				return self::namesProperty($node->var, $propertyName)
					|| self::namesProperty($node->expr, $propertyName);
			}

			if ($node instanceof AssignOp) {
				return self::namesProperty($node->var, $propertyName);
			}

			// A returned form is a mutable reference in the caller's hands, whatever the property's
			// own visibility says.
			return $node instanceof Return_
				&& $node->expr !== null
				&& self::namesProperty($node->expr, $propertyName);
		});
	}

	public static function rewrite(ClassMethod $method, string $propertyName): ClassMethod
	{
		$cloner = new NodeTraverser();
		$cloner->addVisitor(new CloningVisitor());
		$cloned = $cloner->traverse([$method])[0];
		assert($cloned instanceof ClassMethod);

		$variableName = self::variableName($propertyName);

		// The predicate is handed IN rather than called from the visitor's body: an anonymous class
		// carries no namespace of its own, so a reference to this class from inside one reads to the
		// architecture rules as production code reaching into the test namespace.
		$namesProperty = static fn (Node $node): bool => self::namesProperty($node, $propertyName);

		$rewriter = new NodeTraverser();
		$rewriter->addVisitor(new class ($namesProperty, $variableName) extends NodeVisitorAbstract {

			/** @var callable(Node): bool */
			private $namesProperty;

			private string $variableName;

			/**
			 * @param callable(Node): bool $namesProperty
			 */
			public function __construct(callable $namesProperty, string $variableName)
			{
				$this->namesProperty = $namesProperty;
				$this->variableName = $variableName;
			}

			public function enterNode(Node $node): ?Node
			{
				$namesProperty = $this->namesProperty;

				return $namesProperty($node)
					? new Variable($this->variableName, $node->getAttributes())
					: null;
			}

		});
		$rewriter->traverse([$cloned]);

		// The builder's shape is its state at the method end, which is what the fold returns for a
		// tracked value returned there. An earlier method-level `return $this->prop` wins over this
		// one exactly as it would in source order.
		$attributes = [
			'startLine' => $method->getEndLine(),
			'endLine' => $method->getEndLine(),
			'startFilePos' => $method->getEndFilePos(),
			'endFilePos' => $method->getEndFilePos(),
		];
		// Appended rather than unpacked into a fresh literal: array unpacking cannot carry string keys
		// on the PHP 7.4 this analysis runs on, and nothing here needs a copy.
		$stmts = $cloned->stmts ?? [];
		$stmts[] = new Return_(new Variable($variableName, $attributes), $attributes);
		$cloned->stmts = $stmts;

		return $cloned;
	}

	/**
	 * @param callable(Node): bool $predicate
	 */
	private static function find(ClassMethod $method, callable $predicate): bool
	{
		return (new NodeFinder())->findFirst($method->getStmts() ?? [], $predicate) !== null;
	}

}
