<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Inference;

use Nette\Utils\ArrayHash;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Forms\Analyzer\AnalyzerStackFactory;
use OriPhpstan\Nette\Forms\Analyzer\FormShapeAnalyzer;
use OriPhpstan\Nette\Forms\Analyzer\MappedTypeDetector;
use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use OriPhpstan\Nette\Forms\Catalog\Stub\ControlAnnotationValueTypeReader;
use OriPhpstan\Nette\Forms\Component\ReturnShapeSupport;
use OriPhpstan\Nette\Forms\Shape\ComponentPath;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use OriPhpstan\Nette\Forms\Type\FormControlType;
use OriPhpstan\Nette\Forms\Type\FormReplicatorType;
use OriPhpstan\Nette\Forms\Type\FormShapeProjector;
use OriPhpstan\Nette\Forms\Type\FormShapeType;
use OriPhpstan\Nette\Forms\Type\FormValuesProjector;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Parser\Parser;
use PHPStan\Type\ExpressionTypeResolverExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use function count;
use function in_array;
use function is_string;
use function ltrim;
use function spl_object_id;

final class FormAccessExpressionTypeResolver implements ExpressionTypeResolverExtension
{

	private ConfigurationGuard $guard;

	private bool $enabled;

	private Parser $parser;

	private EnclosingFunctionLikeLocator $locator;

	private FormShapeAnalyzer $analyzer;

	private FormFileIndex $index;

	/** @var array<string, true> */
	private array $resolving = [];

	/**
	 * @param list<string> $analysedPaths
	 */
	public function __construct(
		ConfigurationGuard $guard,
		bool $enabled,
		Parser $parser,
		Parser $richParser,
		FormShapeCache $cache,
		ControlAnnotationValueTypeReader $catalogReader,
		array $analysedPaths = []
	)
	{
		// PHPStan builds type extensions with the container, before the reflection provider knows the
		// analysed paths (and in the stub validator's container too), so validate on first use.
		$this->guard = $guard;
		$this->enabled = $enabled;
		$this->parser = $parser;
		$this->locator = new EnclosingFunctionLikeLocator();
		$this->analyzer = AnalyzerStackFactory::build($catalogReader, $cache, $richParser, $analysedPaths);
		$this->index = new FormFileIndex($parser, $this->locator, new IntraProceduralFormGate());
	}

	/**
	 * Inside an onSuccess callback the form is valid, so getValues() projects the
	 * post-validation (filled) shape — required fields are non-null/non-empty.
	 */
	private function isValidatedContext(Expr $expr, Scope $scope): bool
	{
		return EventContextLocator::enclosingEventName(
			$this->parser->parseFile($scope->getFile()),
			$expr,
		) === 'onSuccess';
	}

	public function getType(Expr $expr, Scope $scope): ?Type
	{
		if (!$this->enabled) {
			return null;
		}

		if (!$this->index->hasAnyTrackedForm($scope->getFile())) {
			return null;
		}

		$this->guard->validate();

		if ($expr instanceof MethodCall) {
			return $this->methodCallType($expr, $scope);
		}

		if ($expr instanceof ArrayDimFetch) {
			return $this->offsetType($expr, $scope);
		}

		if ($expr instanceof PropertyFetch) {
			return $this->propertyType($expr, $scope);
		}

		if (!$expr instanceof Variable || !is_string($expr->name)) {
			return null;
		}

		// phpstan-nette compatibility — load-bearing, do not change to a DynamicMethodReturnTypeExtension:
		// PHPStan 2.1.x UNIONS the non-null results of ALL matching DynamicMethodReturnTypeExtensions, and
		// phpstan-nette's FormContainerValuesDynamicReturnTypeExtension ALWAYS returns non-null
		// (ObjectType 'Nette\Utils\ArrayHash') for getValues(). A method-return extension here would yield a
		// polluted `FormShape|ArrayHash` union. ExpressionTypeResolverExtension runs first and short-circuits
		// on the first non-null, so returning a carrier here cleanly OVERRIDES phpstan-nette while returning
		// null cleanly DEFERS to it (interprocedural / non-analysable forms stay exactly as before).
		$guardKey = $scope->getFile() . '|' . spl_object_id($expr);
		if (isset($this->resolving[$guardKey])) {
			return null;
		}

		$this->resolving[$guardKey] = true;
		try {
			$fn = $this->index->enclosingFunctionLike($scope->getFile(), $expr);
			if ($fn === null) {
				return null;
			}

			$tracked = $this->index->trackedVariable($scope->getFile(), $fn, $expr->name);
			if ($tracked === null) {
				return null;
			}

			$shape = $this->index->formShape(
				$scope->getFile(),
				$fn,
				$tracked,
				fn (): FormShape => $this->analyzer->analyzeFormValue(
					$expr,
					$fn,
					$this->locator->taggedRecords($fn, $scope),
					$scope,
				),
			);

			// A shape that names no class has nothing to wrap: FormShapeType IS an ObjectType, and an
			// ObjectType over a placeholder class name is a class core reports class.notFound against
			// on every later member access, not a degrade. Returning null is the degrade this method
			// already documents above - the variable keeps whatever its own declared type says and
			// phpstan-nette answers for it, exactly as for an interprocedural or non-analysable form.
			// The `\mixed` spelling is checked through the shared predicate rather than against null
			// alone, because analyzeFormValue() writes that class deliberately for an entry it could
			// not type, and this carrier is the one place it could have escaped as a type.
			$className = $shape->getClassName();

			return ReturnShapeSupport::isClassNameUnresolved($className)
				? null
				: new FormShapeType($className, $shape);
		} finally {
			unset($this->resolving[$guardKey]);
		}
	}

	private function offsetType(ArrayDimFetch $expr, Scope $scope): ?Type
	{
		if ($expr->dim === null) {
			return null;
		}

		$guardKey = $scope->getFile() . '|' . spl_object_id($expr);
		if (isset($this->resolving[$guardKey])) {
			return null;
		}

		$this->resolving[$guardKey] = true;
		try {
			$baseType = $scope->getType($expr->var);
			if ($baseType instanceof FormReplicatorType) {
				// Delegates to the type's own getOffsetValueType(): an integer offset is the inner
				// row (identical to the old hard-coded reconstruction below), a constant string
				// matching a child added straight onto the replicator holder (e.g. an "addNode"
				// submit button) is THAT child's type, and anything else degrades to IComponent -
				// never hard-coding the row for every offset regardless of what it actually is.
				return $baseType->getOffsetValueType($scope->getType($expr->dim));
			}

			if (!$baseType instanceof FormShapeType) {
				return null;
			}

			$strings = $scope->getType($expr->dim)->getConstantStrings();
			if (count($strings) !== 1) {
				return null;
			}

			// Nette's Container::getComponent() splits a name on IComponent::NameSeparator and descends
			// recursively (vendor/nette/component-model/src/ComponentModel/Container.php:116), so
			// $form['filter']['rep-addNode'] is the SAME lookup as $form['filter']['rep']['addNode'] at
			// runtime. Both spellings go through the shared ComponentPath walk here; a name with no
			// separator is a one-segment path, which the projector resolves with plain offset().
			return FormShapeProjector::offsetPath(
				$baseType->getFormShape(),
				ComponentPath::split($strings[0]->getValue()),
			);
		} finally {
			unset($this->resolving[$guardKey]);
		}
	}

	private function methodCallType(MethodCall $expr, Scope $scope): ?Type
	{
		if (!$expr->name instanceof Identifier || $expr->isFirstClassCallable()) {
			return null;
		}

		$method = $expr->name->toString();
		if ($method === 'getValue') {
			return $this->getValueType($expr, $scope);
		}

		if (in_array($method, ['getValues', 'getUntrustedValues', 'getUnsafeValues'], true)) {
			return $this->getValuesType($expr, $method, $scope);
		}

		return null;
	}

	private function getValueType(MethodCall $expr, Scope $scope): ?Type
	{
		if (count($expr->getArgs()) !== 0) {
			return null;
		}

		$guardKey = $scope->getFile() . '|' . spl_object_id($expr);
		if (isset($this->resolving[$guardKey])) {
			return null;
		}

		$this->resolving[$guardKey] = true;
		try {
			$receiver = $scope->getType($expr->var);
			if (!$receiver instanceof FormControlType) {
				return null;
			}

			return $receiver->getFormGetValueType();
		} finally {
			unset($this->resolving[$guardKey]);
		}
	}

	private function getValuesType(MethodCall $expr, string $method, Scope $scope): ?Type
	{
		$args = $expr->getArgs();
		if (count($args) > 1) {
			return null;
		}

		$guardKey = $scope->getFile() . '|' . spl_object_id($expr);
		if (isset($this->resolving[$guardKey])) {
			return null;
		}

		$this->resolving[$guardKey] = true;
		try {
			$hasArg = count($args) === 1 && !$scope->getType($args[0]->value)->isNull()->yes();
			$argType = $hasArg ? $scope->getType($args[0]->value) : null;

			$base = $scope->getType($expr->var);
			if (!$base instanceof FormShapeType) {
				return null;
			}

			$filled = $base->isValidated() || $this->isValidatedContext($expr, $scope);

			if ($argType !== null) {
				if ($argType->isClassString()->yes()) {
					return $this->crateType($argType, $base->getFormShape(), $filled);
				}

				if ($argType->isTrue()->yes() || $this->isArrayLiteral($argType)) {
					return FormValuesProjector::projectArray($base->getFormShape(), $filled);
				}

				return null;
			}

			// Only no-arg getValues() applies the form's own setMappedType(); the
			// getUntrustedValues()/getUnsafeValues() crate ignores it (Nette defaults their
			// $returnType to ArrayHash, never consulting mappedType).
			if ($method === 'getValues') {
				$mapped = $base->getFormShape()->getMappedType() ?? $this->mappedType($expr, $scope);
				if ($mapped !== null) {
					return new ObjectType($mapped);
				}
			}

			return FormValuesProjector::projectObject($base->getFormShape(), ArrayHash::class, $filled);
		} finally {
			unset($this->resolving[$guardKey]);
		}
	}

	/**
	 * getValues($class) named a class, and which class decides whether the form's own values shape goes
	 * into it: a universal CRATE (ArrayHash, stdClass) is a bag Nette fills with the form's fields and
	 * carries no members of its own, so the projection is the only thing that can type a field read on
	 * it, while a mapped DTO declares its own properties and PHPStan types those natively.
	 * FormValuesProjector::isUniversalCrate() is the one place that line is drawn, asked here and by
	 * FormGetValuesDynamicReturnTypeExtension, which is the channel the same call takes when the
	 * receiver is not a tracked local.
	 *
	 * This used to hand back the bare class for BOTH, so `$form->getValues(stdClass::class)` resolved to
	 * `stdClass` with every field `mixed` inside the file that builds the form, while
	 * `$this['form']->getValues(stdClass::class)` in the same class kept `stdClass{name: string, …}`.
	 * The no-arg spelling never lost anything, which is why the gap survived: it is the crate ARGUMENT
	 * that was unreachable for the projection.
	 *
	 * A class-string the analysis cannot resolve to exactly one class name keeps the bare answer, which
	 * is the degrade this method already had - there is no shape to choose a crate for.
	 */
	private function crateType(Type $argType, FormShape $shape, bool $filled): Type
	{
		$object = $argType->getClassStringObjectType();
		$classNames = $object->getObjectClassNames();
		if (count($classNames) !== 1) {
			return $object;
		}

		$className = ltrim($classNames[0], '\\');

		return FormValuesProjector::isUniversalCrate($className)
			? FormValuesProjector::projectObject($shape, $className, $filled)
			: $object;
	}

	private function isArrayLiteral(Type $type): bool
	{
		$strings = $type->getConstantStrings();

		return count($strings) === 1 && $strings[0]->getValue() === 'array';
	}

	private function mappedType(MethodCall $expr, Scope $scope): ?string
	{
		if (!$expr->var instanceof Variable || !is_string($expr->var->name)) {
			return null;
		}

		$fn = $this->index->enclosingFunctionLike($scope->getFile(), $expr);
		if ($fn === null) {
			return null;
		}

		return MappedTypeDetector::detect($fn->getStmts() ?? [], $expr->var->name);
	}

	private function propertyType(PropertyFetch $expr, Scope $scope): ?Type
	{
		if (!$expr->name instanceof Identifier) {
			return null;
		}

		$property = $expr->name->toString();

		$guardKey = $scope->getFile() . '|' . spl_object_id($expr);
		if (isset($this->resolving[$guardKey])) {
			return null;
		}

		$this->resolving[$guardKey] = true;
		try {
			$base = $scope->getType($expr->var);
			if ($property === 'values' && $base instanceof FormShapeType) {
				return FormValuesProjector::projectObject($base->getFormShape());
			}

			return null;
		} finally {
			unset($this->resolving[$guardKey]);
		}
	}

}
