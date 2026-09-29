<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte3;

use Latte\Essential\Nodes\VarNode;

// Latte's own {var}/{default} node plus the declared type of each assignment, which
// VarNode::create() parses and discards.
final class VarDeclarationNode extends VarNode
{

	/** @var array<int, string|null> */
	public array $types = [];

}
