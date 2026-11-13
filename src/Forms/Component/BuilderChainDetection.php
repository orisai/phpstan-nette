<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Component;

use PHPStan\Reflection\ClassReflection;

final class BuilderChainDetection
{

	private ClassReflection $declaringClass;

	private string $methodName;

	public function __construct(ClassReflection $declaringClass, string $methodName)
	{
		$this->declaringClass = $declaringClass;
		$this->methodName = $methodName;
	}

	public function getDeclaringClass(): ClassReflection
	{
		return $this->declaringClass;
	}

	public function getMethodName(): string
	{
		return $this->methodName;
	}

}
