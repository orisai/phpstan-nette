<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Rule;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;

final class ComponentShapeDumpRule extends BaseDumpAssertRule
{

	private ComponentShapeDescriber $describer;

	public function __construct(ConfigurationGuard $guard, bool $enabled, ComponentShapeDescriber $describer)
	{
		parent::__construct($guard, $enabled);
		$this->describer = $describer;
	}

	protected function functionName(): string
	{
		return 'oriphpstan\\nette\\forms\\testing\\dumpcomponent';
	}

	protected function minArgs(): int
	{
		return 1;
	}

	protected function buildErrors(array $args, FuncCall $node, Scope $scope): array
	{
		$maxDepth = ComponentShapeDescriber::intArg($args[1] ?? null, $scope);
		$formValues = ComponentShapeDescriber::boolArg($args[2] ?? null, $scope);

		return [
			$this->buildError(
				$this->describer->describe($args[0]->value, $node, $scope, $maxDepth, $formValues),
				'orisaiNette.forms.dump',
				$node,
			),
		];
	}

}
