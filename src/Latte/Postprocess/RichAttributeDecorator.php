<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess;

use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PHPStan\DependencyInjection\Container;
use PHPStan\Parser\RichParser;

final class RichAttributeDecorator
{

	private Container $container;

	public function __construct(Container $container)
	{
		$this->container = $container;
	}

	// PHPStan core rules (e.g. ContinueBreakInLoopRule) read attributes that only RichParser's
	// tagged visitor suite sets (RichParser::parseString), never populated for an AST that never
	// went through RichParser - which is every .latte-derived AST, since LatteRoutingParser builds
	// its own synthetic tree instead of delegating to RichParser. Re-running the same tagged
	// visitors here (minus NameResolver/pipe transformers, which RichParser runs separately and
	// which our own pipeline's parser already applies/doesn't need) closes that gap.

	/**
	 * @param array<Stmt> $stmts
	 * @return array<Stmt>
	 */
	public function decorate(array $stmts): array
	{
		$traverser = new NodeTraverser();
		foreach ($this->container->getServicesByTag(RichParser::VISITOR_SERVICE_TAG) as $visitor) {
			$traverser->addVisitor($visitor);
		}

		/** @var array<Stmt> $decorated */
		$decorated = $traverser->traverse($stmts);

		return $decorated;
	}

}
