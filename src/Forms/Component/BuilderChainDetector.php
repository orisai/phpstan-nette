<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Component;

use Nette\Forms\Container as NetteContainer;
use Nette\Forms\Form as NetteForm;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ObjectType;

final class BuilderChainDetector
{

	public static function detect(
		MethodCall $call,
		Scope $scope,
		?string $receiverClass = null
	): ?BuilderChainDetection
	{
		if (!$call->name instanceof Identifier) {
			return null;
		}

		$methodName = $call->name->toString();
		// On the on-demand path the caller resolves the receiver scope-free; a foreign
		// caller's $scope->getType($call->var) would misresolve a $this->prop receiver.
		$receiverType = $receiverClass !== null ? new ObjectType($receiverClass) : $scope->getType($call->var);
		if (!$receiverType->hasMethod($methodName)->yes()) {
			return null;
		}

		$methodReflection = $receiverType->getMethod($methodName, $scope);
		$returnType = $methodReflection->getVariants()[0]->getReturnType();
		$isForm = (new ObjectType(NetteForm::class))->isSuperTypeOf($returnType)->yes()
			|| (new ObjectType(NetteContainer::class))->isSuperTypeOf($returnType)->yes();
		if (!$isForm) {
			return null;
		}

		// Never answered from the factory-method shape entry: its producer joins every return arm, each
		// caller below computes its own way, and a hit would make the answer depend on which ran first.
		return new BuilderChainDetection($methodReflection->getDeclaringClass(), $methodName);
	}

}
