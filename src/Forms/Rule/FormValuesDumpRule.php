<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Rule;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;

final class FormValuesDumpRule extends BaseDumpAssertRule
{

	private FormValuesDescriber $describer;

	public function __construct(ConfigurationGuard $guard, bool $enabled, FormValuesDescriber $describer)
	{
		parent::__construct($guard, $enabled);
		$this->describer = $describer;
	}

	protected function functionName(): string
	{
		return 'oriphpstan\\nette\\forms\\testing\\dumpformvalues';
	}

	protected function minArgs(): int
	{
		return 1;
	}

	protected function buildErrors(array $args, FuncCall $node, Scope $scope): array
	{
		return [
			$this->buildError(
				$this->describer->describe($args[0]->value, $node, $scope),
				'orisai.nette.forms.formValuesDump',
				$node,
			),
		];
	}

}
