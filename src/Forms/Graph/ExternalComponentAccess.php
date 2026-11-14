<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Graph;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitorAbstract;
use function assert;

/**
 * A component reached from outside its builder (`$control['form']`, `$this['ctrl']['form']`), said in
 * the vocabulary the shape walk speaks: a CLONE of the mutating method in which every matching access
 * reads as one variable no PHP source can bind, so the container-param walk reports what that method
 * adds to it. The same normalisation PropertyFormAccess applies to a property-held form.
 */
final class ExternalComponentAccess
{

	private function __construct()
	{
	}

	public static function variableName(string $accessKey): string
	{
		return '@mutation:' . $accessKey;
	}

	/**
	 * @param callable(Node): bool $matches
	 */
	public static function matchesInsideClosure(ClassMethod $method, callable $matches): bool
	{
		$finder = new NodeFinder();
		foreach ($finder->find(
			$method->getStmts() ?? [],
			static fn (Node $n): bool => $n instanceof Closure || $n instanceof ArrowFunction,
		) as $closure) {
			if ($finder->findFirst([$closure], $matches) !== null) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param callable(Node): bool $matches
	 */
	public static function rewrite(ClassMethod $method, callable $matches, string $variableName): ClassMethod
	{
		$cloner = new NodeTraverser();
		$cloner->addVisitor(new CloningVisitor());
		$cloned = $cloner->traverse([$method])[0];
		assert($cloned instanceof ClassMethod);

		$rewriter = new NodeTraverser();
		$rewriter->addVisitor(new class ($matches, $variableName) extends NodeVisitorAbstract {

			/** @var callable(Node): bool */
			private $matches;

			private string $variableName;

			/**
			 * @param callable(Node): bool $matches
			 */
			public function __construct(callable $matches, string $variableName)
			{
				$this->matches = $matches;
				$this->variableName = $variableName;
			}

			public function enterNode(Node $node): ?Node
			{
				$matches = $this->matches;

				return $matches($node) ? new Variable($this->variableName, $node->getAttributes()) : null;
			}

		});
		$rewriter->traverse([$cloned]);

		return $cloned;
	}

}
