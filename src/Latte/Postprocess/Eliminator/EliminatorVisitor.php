<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess\Eliminator;

use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use PhpParser\NodeVisitorAbstract;

abstract class EliminatorVisitor extends NodeVisitorAbstract
{

	private ShapeFamily $family;

	private ?PatternSet $patterns = null;

	public function __construct(ShapeFamily $family)
	{
		$this->family = $family;
	}

	abstract public function describePattern(): string;

	final protected function patterns(): PatternSet
	{
		return $this->patterns ??= FamilyPatterns::for($this->family, static::class);
	}

}
