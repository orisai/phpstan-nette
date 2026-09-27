<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Rule;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use function count;

/**
 * The assert counterpart of ComponentShapeDumpRule: renders the resolved shape of the first
 * argument the same way dumpComponent does and reports a mismatch against the literal second
 * argument. Modelled on PHPStan's own assertType test helper.
 */
final class ComponentShapeAssertRule extends BaseDumpAssertRule
{

	private ComponentShapeDescriber $describer;

	public function __construct(ConfigurationGuard $guard, bool $enabled, ComponentShapeDescriber $describer)
	{
		parent::__construct($guard, $enabled);
		$this->describer = $describer;
	}

	protected function functionName(): string
	{
		return 'oriphpstan\\nette\\forms\\testing\\assertcomponent';
	}

	protected function minArgs(): int
	{
		return 2;
	}

	protected function buildErrors(array $args, FuncCall $node, Scope $scope): array
	{
		$expectedStrings = $scope->getType($args[1]->value)->getConstantStrings();
		if (count($expectedStrings) !== 1) {
			return [];
		}

		$expected = $expectedStrings[0]->getValue();
		$maxDepth = ComponentShapeDescriber::intArg($args[2] ?? null, $scope);
		$formValues = ComponentShapeDescriber::boolArg($args[3] ?? null, $scope);

		$actual = $this->describer->describe($args[0]->value, $node, $scope, $maxDepth, $formValues);
		if ($actual === $expected) {
			return [];
		}

		return [
			$this->buildError(
				"Component shape does not match assertComponent() expectation.\n"
				. "expected:\n" . $expected . "\n"
				. "actual:\n" . $actual,
				'orisaiNette.forms.assert',
				$node,
			),
		];
	}

}
