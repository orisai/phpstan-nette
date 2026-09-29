<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte3;

use Generator;
use Latte\Compiler\Nodes\AreaNode;
use Latte\Compiler\Nodes\StatementNode;
use Latte\Compiler\PrintContext;

// An unknown tag or n:attribute claimed by the analysis extension: prints nothing of its own and
// keeps its content (or the element it decorates) compiled as usual.
final class PassthroughNode extends StatementNode
{

	public ?AreaNode $content = null;

	public function print(PrintContext $context): string
	{
		return $this->content === null ? '' : $this->content->print($context);
	}

	public function &getIterator(): Generator
	{
		if ($this->content !== null) {
			yield $this->content;
		}
	}

}
