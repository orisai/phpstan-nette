<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Rule;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;
use function strncmp;

/**
 * A createComponent*() method that returns a Nette\Forms\Form which is not a
 * Nette\Application\UI\Form is almost always a bug: only UI\Form implements
 * SignalReceiver and wires up the presenter-specific behaviour (signal handling,
 * cross-request submission, element name resolution). A bare Nette\Forms\Form
 * attached to a presenter silently loses all of that.
 *
 * @implements Rule<ClassMethod>
 */
final class CreateComponentReturnsUiFormRule implements Rule
{

	private const FORMS_FORM = 'Nette\Forms\Form';

	private const UI_FORM = 'Nette\Application\UI\Form';

	private bool $enabled;

	public function __construct(ConfigurationGuard $guard, bool $enabled)
	{
		$guard->validate();
		$this->enabled = $enabled;
	}

	public function getNodeType(): string
	{
		return ClassMethod::class;
	}

	/**
	 * @return list<IdentifierRuleError>
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		if (!$this->enabled) {
			return [];
		}

		$methodName = $node->name->toString();
		if (strncmp($methodName, 'createComponent', 15) !== 0) {
			return [];
		}

		if (!$scope->isInClass()) {
			return [];
		}

		$returnType = $scope->getClassReflection()
			->getNativeMethod($methodName)
			->getVariants()[0]
			->getReturnType();

		if (!$this->isFormsFormButNotUiForm($returnType)) {
			return [];
		}

		return [
			RuleErrorBuilder::message(
				$methodName . '() returns ' . $returnType->describe(VerbosityLevel::typeOnly())
					. ', which is a Nette\Forms\Form but not a Nette\Application\UI\Form. '
					. 'Presenter-attached forms must extend Nette\Application\UI\Form '
					. 'so that signal handling and submission work.',
			)
				->identifier('orisaiNette.forms.createComponentNonUiForm')
				->line($node->getStartLine())
				->build(),
		];
	}

	private function isFormsFormButNotUiForm(Type $returnType): bool
	{
		$formsForm = new ObjectType(self::FORMS_FORM);
		if (!$formsForm->isSuperTypeOf($returnType)->yes()) {
			return false;
		}

		return !(new ObjectType(self::UI_FORM))->isSuperTypeOf($returnType)->yes();
	}

}
