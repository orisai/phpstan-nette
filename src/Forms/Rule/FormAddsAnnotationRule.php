<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Rule;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Forms\Catalog\Stub\ControlAnnotationValueTypeReader;
use PhpParser\Node;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports every `@form-adds` occurrence the reader refused, so an annotation that does not work is
 * never merely inert.
 *
 * The reader is the single authority: it drops exactly the occurrences reported here, so a tag is
 * either honoured by the model or reported by this rule, never both and never neither. What this
 * rule adds is the reach — a declaration the model never asks about (a helper nobody calls, a method
 * on a class that is not a container at all) is validated all the same, because a tag is a statement
 * about the code whether or not anything reads it today.
 *
 * The node type is FunctionLike rather than ClassMethod so a tag written on a free function, a
 * closure or an arrow function is caught by the scope gate instead of being invisible.
 *
 * @implements Rule<FunctionLike>
 */
final class FormAddsAnnotationRule implements Rule
{

	private bool $enabled;

	private ControlAnnotationValueTypeReader $reader;

	public function __construct(ConfigurationGuard $guard, bool $enabled, ControlAnnotationValueTypeReader $reader)
	{
		$guard->validate();
		$this->enabled = $enabled;
		$this->reader = $reader;
	}

	public function getNodeType(): string
	{
		return FunctionLike::class;
	}

	/**
	 * @return list<IdentifierRuleError>
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		if (!$this->enabled) {
			return [];
		}

		$docComment = $node->getDocComment();
		$text = $docComment === null ? null : $docComment->getText();
		if (!$this->reader->declaresAdds($text)) {
			return [];
		}

		$classReflection = $scope->isInClass() ? $scope->getClassReflection() : null;
		if (!$node instanceof ClassMethod || $classReflection === null) {
			return [
				RuleErrorBuilder::message(
					ControlAnnotationValueTypeReader::ADDS_TAG_NAME . ' is only valid on a method declared on a '
						. 'Nette\Forms\Container subclass, and this is not a method of one.',
				)
					->identifier('orisaiNette.forms.outsideContainer')
					->line($node->getStartLine())
					->build(),
			];
		}

		$methodName = $node->name->toString();
		if (!$classReflection->hasNativeMethod($methodName)) {
			return [];
		}

		$errors = [];
		foreach (
			$this->reader->addsViolationsForMethod(
				$text,
				$classReflection->getNativeMethod($methodName),
				!$classReflection->isTrait(),
			) as $violation
		) {
			$errors[] = RuleErrorBuilder::message($violation->getMessage())
				->identifier($violation->getIdentifier())
				->line($node->getStartLine())
				->build();
		}

		return $errors;
	}

}
