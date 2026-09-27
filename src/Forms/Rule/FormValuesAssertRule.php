<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Rule;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use function count;

/**
 * The assert counterpart of FormValuesDumpRule: projects the read-values shape of the first
 * argument the same way dumpFormValues does and reports a mismatch against the literal second.
 */
final class FormValuesAssertRule extends BaseDumpAssertRule
{

	private FormValuesDescriber $describer;

	public function __construct(ConfigurationGuard $guard, bool $enabled, FormValuesDescriber $describer)
	{
		parent::__construct($guard, $enabled);
		$this->describer = $describer;
	}

	protected function functionName(): string
	{
		return 'oriphpstan\\nette\\forms\\testing\\assertformvalues';
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
		$actual = $this->describer->describe($args[0]->value, $node, $scope);
		if ($actual === $expected) {
			return [];
		}

		return [
			$this->buildError(
				"Form values do not match assertFormValues() expectation.\n"
				. "expected:\n" . $expected . "\n"
				. "actual:\n" . $actual,
				'orisaiNette.forms.formValuesAssert',
				$node,
			),
		];
	}

}
