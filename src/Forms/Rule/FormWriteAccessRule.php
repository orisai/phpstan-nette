<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Rule;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Forms\Catalog\ControlAcceptedTypeResolver;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use OriPhpstan\Nette\Forms\Type\FormControlType;
use OriPhpstan\Nette\Forms\Type\FormShapeType;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;
use function count;
use function in_array;

/**
 * @implements Rule<MethodCall>
 */
final class FormWriteAccessRule implements Rule
{

	private bool $enabled;

	private ControlAcceptedTypeResolver $resolver;

	public function __construct(ConfigurationGuard $guard, bool $enabled, TypeStringResolver $typeStringResolver)
	{
		$guard->validate();
		$this->enabled = $enabled;
		$this->resolver = new ControlAcceptedTypeResolver($typeStringResolver);
	}

	public function getNodeType(): string
	{
		return MethodCall::class;
	}

	public function processNode(Node $node, Scope $scope): array
	{
		if (!$this->enabled) {
			return [];
		}

		if (!$node->name instanceof Identifier) {
			return [];
		}

		if ($node->isFirstClassCallable()) {
			return [];
		}

		$method = $node->name->toString();

		if (in_array($method, ['setValue', 'setDefaultValue'], true)) {
			return $this->processSetValue($node, $scope);
		}

		if (in_array($method, ['setValues', 'setDefaults'], true)) {
			return $this->processSetValues($node, $scope);
		}

		return [];
	}

	/** @return list<IdentifierRuleError> */
	private function processSetValue(MethodCall $node, Scope $scope): array
	{
		$args = $node->getArgs();
		if (count($args) !== 1) {
			return [];
		}

		$receiver = $scope->getType($node->var);
		if (!$receiver instanceof FormControlType) {
			return [];
		}

		$name = $this->offsetName($node->var, $scope);
		if ($name === null) {
			return [];
		}

		$spec = $receiver->getFormAcceptedSetSpec();
		if ($spec === null) {
			return [];
		}

		if ($this->resolver->isNoOp($spec)) {
			return [
				RuleErrorBuilder::message(
					"setValue() on upload field '" . $name . "' has no effect.",
				)
					->identifier('orisai.nette.forms.writeNoEffect')
					->line($node->getStartLine())
					->build(),
			];
		}

		$argType = $scope->getType($args[0]->value);
		$accepted = $this->resolver->accepted($spec, false);
		if ($accepted->accepts($argType, true)->yes()) {
			return [];
		}

		return [
			RuleErrorBuilder::message(
				"Form field '" . $name . "' (" . $receiver->getClassName() . ') accepts '
				. $this->resolver->acceptedLabel($spec) . ', '
				. $this->describeGiven($argType) . ' given.',
			)
				->identifier('orisai.nette.forms.writeType')
				->line($node->getStartLine())
				->build(),
		];
	}

	/** @return list<IdentifierRuleError> */
	private function processSetValues(MethodCall $node, Scope $scope): array
	{
		$args = $node->getArgs();
		if (count($args) < 1) {
			return [];
		}

		$base = $scope->getType($node->var);
		if (!$base instanceof FormShapeType) {
			return [];
		}

		$argType = $scope->getType($args[0]->value);

		if (
			$argType->isArray()->yes()
			|| $argType->isObject()->yes()
			|| $argType->isIterable()->yes()
		) {
			$errors = [];
			$this->checkShape($base->getFormShape(), $argType, $node, $errors);

			return $errors;
		}

		return [
			RuleErrorBuilder::message(
				'Form values must be an array or Traversable, '
				. $argType->describe(VerbosityLevel::typeOnly()) . ' given.',
			)
				->identifier('orisai.nette.forms.writeType')
				->line($node->getStartLine())
				->build(),
		];
	}

	/**
	 * @param list<IdentifierRuleError> $errors
	 */
	private function checkShape(FormShape $shape, Type $argType, MethodCall $node, array &$errors): void
	{
		$constantArrays = $argType->getConstantArrays();
		if (count($constantArrays) !== 1) {
			return;
		}

		$constantArray = $constantArrays[0];
		$keyTypes = $constantArray->getKeyTypes();
		$valueTypes = $constantArray->getValueTypes();

		$slots = $shape->getSlots();
		$containers = $shape->getContainers();

		foreach ($keyTypes as $i => $keyType) {
			$keyStrings = $keyType->getConstantStrings();
			if (count($keyStrings) !== 1) {
				continue;
			}

			$key = $keyStrings[0]->getValue();
			$valueType = $valueTypes[$i];

			if (isset($containers[$key])) {
				$this->checkShape($containers[$key], $valueType, $node, $errors);

				continue;
			}

			if (!isset($slots[$key])) {
				continue;
			}

			$slot = $slots[$key];
			$spec = $slot->getAcceptedSetSpec();
			if ($spec === null || $this->resolver->isNoOp($spec)) {
				continue;
			}

			$accepted = $this->resolver->accepted($spec, false);
			if ($accepted->accepts($valueType, true)->yes()) {
				continue;
			}

			$errors[] = RuleErrorBuilder::message(
				"Form field '" . $slot->getName() . "' (" . ($slot->getControlClass() ?? '\\mixed') . ') accepts '
				. $this->resolver->acceptedLabel($spec) . ', '
				. $this->describeGiven($valueType) . ' given.',
			)
				->identifier('orisai.nette.forms.writeType')
				->line($node->getStartLine())
				->build();
		}
	}

	private function describeGiven(Type $type): string
	{
		$level = $type->isArray()->yes() ? VerbosityLevel::value() : VerbosityLevel::typeOnly();

		return $type->describe($level);
	}

	private function offsetName(Node\Expr $expr, Scope $scope): ?string
	{
		if (!$expr instanceof ArrayDimFetch || $expr->dim === null) {
			return null;
		}

		$strings = $scope->getType($expr->dim)->getConstantStrings();
		if (count($strings) !== 1) {
			return null;
		}

		return $strings[0]->getValue();
	}

}
