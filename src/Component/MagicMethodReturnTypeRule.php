<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Component;

use Nette\Application\UI\Component;
use Nette\Application\UI\Presenter;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use function str_starts_with;

/**
 * @implements Rule<Node\Stmt\ClassMethod>
 */
final class MagicMethodReturnTypeRule implements Rule
{

	private bool $enabled;

	public function __construct(ConfigurationGuard $guard, bool $enabled)
	{
		$guard->validate();
		$this->enabled = $enabled;
	}

	public function getNodeType(): string
	{
		return Node\Stmt\ClassMethod::class;
	}

	public function processNode(Node $node, Scope $scope): array
	{
		if (!$this->enabled) {
			return [];
		}

		$classReflection = $scope->getClassReflection();

		if ($classReflection === null) {
			return [];
		}

		$methodName = $node->name->toString();

		if (str_starts_with($methodName, 'action') || str_starts_with($methodName, 'render')) {
			if (!$classReflection->is(Presenter::class)) {
				return [];
			}

			$formatMethod = str_starts_with($methodName, 'action')
				? 'formatActionMethod'
				: 'formatRenderMethod';
			$declaringClass = Presenter::class;

		} elseif (str_starts_with($methodName, 'handle')) {
			if (!$classReflection->is(Component::class)) {
				return [];
			}

			$formatMethod = 'formatSignalMethod';
			$declaringClass = Component::class;

		} else {
			return [];
		}

		$returnType = $node->getReturnType();

		if ($returnType instanceof Node\Identifier) {
			$typeName = $returnType->toString();

			if ($typeName === 'void' || $typeName === 'never') {
				return [];
			}
		}

		$className = $classReflection->isAnonymous() ? 'class@anonymous' : $classReflection->getName();

		$error = RuleErrorBuilder::message(
			"Method $className::$methodName() must return void or never.",
		)
			->identifier('orisaiNette.component.magicMethodReturnType');

		if ($classReflection->getNativeMethod($formatMethod)->getDeclaringClass()->getName() !== $declaringClass) {
			$error->addTip("$formatMethod() is overridden, this might be a false positive.");
		}

		return [$error->build()];
	}

}
