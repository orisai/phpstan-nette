<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte3;

use Latte\Essential\Nodes\VarTypeNode;

// Latte's own {varType} node (prints nothing) carrying the declaration it was parsed from.
final class VarTypeDeclarationNode extends VarTypeNode
{

	public CapturedDeclaration $declaration;

	public function __construct(CapturedDeclaration $declaration)
	{
		$this->declaration = $declaration;
	}

}
