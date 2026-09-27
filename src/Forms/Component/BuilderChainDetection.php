<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Component;

use OriPhpstan\Nette\Forms\Shape\FormShape;
use PHPStan\Reflection\ClassReflection;

final class BuilderChainDetection
{

	private ?FormShape $cachedShape;

	private ?ClassReflection $declaringClass;

	private ?string $methodName;

	private function __construct(?FormShape $cachedShape, ?ClassReflection $declaringClass, ?string $methodName)
	{
		$this->cachedShape = $cachedShape;
		$this->declaringClass = $declaringClass;
		$this->methodName = $methodName;
	}

	public static function cacheHit(FormShape $shape): self
	{
		return new self($shape, null, null);
	}

	public static function cacheMiss(ClassReflection $declaringClass, string $methodName): self
	{
		return new self(null, $declaringClass, $methodName);
	}

	public function getCachedShape(): ?FormShape
	{
		return $this->cachedShape;
	}

	public function getDeclaringClass(): ?ClassReflection
	{
		return $this->declaringClass;
	}

	public function getMethodName(): ?string
	{
		return $this->methodName;
	}

}
