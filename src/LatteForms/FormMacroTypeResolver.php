<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\LatteForms;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Forms\Component\ReturnShapeSupport;
use OriPhpstan\Nette\Forms\Shape\ComponentPath;
use OriPhpstan\Nette\Forms\Type\FormReplicatorType;
use OriPhpstan\Nette\Forms\Type\FormShapeProjector;
use OriPhpstan\Nette\Forms\Type\FormShapeType;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Runtime\Helpers;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ExpressionTypeResolverExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use function array_unique;
use function assert;
use function count;
use function in_array;
use function ltrim;
use function spl_object_id;
use function substr_compare;

// The bridge's second consumer of the template -> renderer -> shape join, this one at analysis
// time: the eliminator leaves {form x} as Helpers::form('x') (UiForm) and every control reference as
// Helpers::formField('x') (BaseControl), and this resolver narrows both to the class the paired
// builder really attached. A paired {label x} is Helpers::formLabel('x') (Html), a typed alias of
// formField('x')->getLabel() that needs no narrowing. Every rule below is one-sided like LatteFormsRule's: an unresolved
// renderer, a second resolved form, a dynamic name or two renderers disagreeing on a class all keep
// the eliminator's wide type. A wrong narrow type is the failure this ranks worst.
final class FormMacroTypeResolver implements ExpressionTypeResolverExtension
{

	private const METHOD_FORM = 'form';

	private const METHOD_FIELD = 'formField';

	private const METHOD_CONTAINER = 'formContainer';

	private FormMacroCollector $collector;

	private FormPairing $pairing;

	private LatteUniverse $universe;

	private ConfigurationGuard $guard;

	private bool $enabled;

	/** @var array<string, true> */
	private array $resolving = [];

	public function __construct(
		ConfigurationGuard $guard,
		FormMacroCollector $collector,
		FormPairing $pairing,
		LatteUniverse $universe
	)
	{
		// Type extensions are built before the reflection provider knows the analysed paths (and in
		// the stub validator's container too), so validate on first use.
		$this->guard = $guard;
		$this->collector = $collector;
		$this->pairing = $pairing;
		$this->universe = $universe;
		$this->enabled = $guard->isBridgeEnabled() && $guard->isLatteDiscoveryEnabled();
	}

	public function getType(Expr $expr, Scope $scope): ?Type
	{
		if (!$this->enabled) {
			return null;
		}

		if ($expr instanceof StaticCall) {
			return $this->helperCallType($expr, $scope);
		}

		if ($expr instanceof ArrayDimFetch && $expr->dim !== null && self::isLatteFile($scope->getFile())) {
			$this->guard->validate();

			return $this->offsetType($expr, $scope);
		}

		return null;
	}

	public function formShapeFor(string $templateRelPath, string $formName, Scope $scope): ?Type
	{
		$this->pairing->bindScope($scope);
		if ($this->pairing->hasUnresolvedRenderer($templateRelPath, $formName)) {
			return null;
		}

		$forms = $this->pairing->formsFor($templateRelPath, $formName);
		if (count($forms) !== 1) {
			return null;
		}

		$className = $forms[0]->getShape()->getClassName();
		if (ReturnShapeSupport::isClassNameUnresolved($className)) {
			return null;
		}

		$className = ltrim($className, '\\');
		if ($forms[0]->isMutatedExternallyAnywhere()) {
			return new ObjectType($className);
		}

		return new FormShapeType($className, $forms[0]->shapeForTyping());
	}

	public function controlClassAt(string $templateRelPath, int $line, string $name, Scope $scope): ?string
	{
		$formName = null;
		$containerPath = null;
		foreach ($this->collector->sitesFor($templateRelPath) as $site) {
			foreach ($site->getReferences() as $reference) {
				if ($reference->getLine() !== $line || $reference->getName() !== $name) {
					continue;
				}

				if (
					$formName !== null
					&& (
						$formName !== $site->getFormName()
						|| $containerPath !== $reference->getContainerPath()
					)
				) {
					return null;
				}

				$formName = $site->getFormName();
				$containerPath = $reference->getContainerPath();
			}
		}

		if ($formName === null || $containerPath === null) {
			return null;
		}

		$this->pairing->bindScope($scope);
		if ($this->pairing->hasUnresolvedRenderer($templateRelPath, $formName)) {
			return null;
		}

		$forms = $this->pairing->formsFor($templateRelPath, $formName);
		if ($forms === []) {
			return null;
		}

		$classes = [];
		foreach ($forms as $form) {
			$identity = $form->identify($containerPath, $name);
			$candidates = $identity === null ? null : $identity->getClasses();
			if ($candidates === null || count($candidates) !== 1) {
				return null;
			}

			$classes[] = ltrim($candidates[0], '\\');
		}

		return count(array_unique($classes)) === 1 ? $classes[0] : null;
	}

	private function helperCallType(StaticCall $call, Scope $scope): ?Type
	{
		if (
			!$call->class instanceof Name
			|| ltrim($call->class->toString(), '\\') !== Helpers::class
			|| !$call->name instanceof Identifier
			|| count($call->args) !== 1
			|| !$call->args[0] instanceof Arg
			|| !$call->args[0]->value instanceof String_
		) {
			return null;
		}

		$method = $call->name->toString();
		if (!in_array($method, [self::METHOD_FORM, self::METHOD_FIELD, self::METHOD_CONTAINER], true)) {
			return null;
		}

		$this->guard->validate();

		$name = $call->args[0]->value->value;
		$relPath = $this->universe->relativePath($scope->getFile());

		if ($method === self::METHOD_FORM) {
			return $this->formShapeFor($relPath, $name, $scope);
		}

		$class = $this->controlClassAt($relPath, self::lineOf($call), $name, $scope);

		return $class === null ? null : new ObjectType($class);
	}

	private function offsetType(ArrayDimFetch $expr, Scope $scope): ?Type
	{
		assert($expr->dim !== null);
		$guardKey = $scope->getFile() . '|' . spl_object_id($expr);
		if (isset($this->resolving[$guardKey])) {
			return null;
		}

		$this->resolving[$guardKey] = true;
		try {
			$base = $scope->getType($expr->var);
			if ($base instanceof FormReplicatorType) {
				return $base->getOffsetValueType($scope->getType($expr->dim));
			}

			if (!$base instanceof FormShapeType) {
				return null;
			}

			$names = $scope->getType($expr->dim)->getConstantStrings();
			if ($names === []) {
				return null;
			}

			$types = [];
			foreach ($names as $name) {
				$type = FormShapeProjector::offsetPath($base->getFormShape(), ComponentPath::split($name->getValue()));
				if ($type === null) {
					return null;
				}

				$types[] = $type;
			}

			return TypeCombinator::union(...$types);
		} finally {
			unset($this->resolving[$guardKey]);
		}
	}

	private static function lineOf(StaticCall $call): int
	{
		$line = $call->getStartLine();

		return $line > 0 ? $line : $call->getArgs()[0]->value->getStartLine();
	}

	private static function isLatteFile(string $file): bool
	{
		return substr_compare($file, '.latte', -6) === 0;
	}

}
