<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Component;

use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use function ucfirst;

final class ComponentConstructionLocator
{

	public function locate(Class_ $class, string $componentName): ?ClassMethod
	{
		return $class->getMethod('createComponent' . ucfirst($componentName));
	}

}
