<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess\Eliminator;

use PhpParser\NodeVisitorAbstract;

abstract class EliminatorVisitor extends NodeVisitorAbstract
{

	abstract public function describePattern(): string;

}
