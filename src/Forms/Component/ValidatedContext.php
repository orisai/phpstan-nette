<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Component;

use OriPhpstan\Nette\Forms\Type\FormShapeType;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Variable;
use PHPStan\Analyser\Scope;

final class ValidatedContext
{

	private ContainerModel $ext;

	public function __construct(ContainerModel $ext)
	{
		$this->ext = $ext;
	}

	public function isFilledGetValues(?FormShapeType $receiverType, Expr $call, Scope $scope): bool
	{
		return ($receiverType !== null && $receiverType->isValidated())
			|| $this->ext->isOnSuccessContext($call, $scope)
			|| $this->ext->isOnSuccessHandlerMethod($scope);
	}

	public function isFilledEventParam(Variable $var, Scope $scope): bool
	{
		return $this->ext->eventCallbackName($var, $scope) === 'onSuccess';
	}

}
