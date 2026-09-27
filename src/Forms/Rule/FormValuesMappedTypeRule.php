<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Rule;

use Nette\Utils\ArrayHash;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Forms\Component\ContainerModel;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use OriPhpstan\Nette\Forms\Type\FormValuesProjector;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\BooleanType;
use PHPStan\Type\ErrorType;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\UnionType;
use PHPStan\Type\VerbosityLevel;
use stdClass;
use function array_keys;
use function array_merge;
use function count;
use function in_array;
use function ltrim;
use function sprintf;

/**
 * Nette maps form values into a custom type (`$form->setMappedType(Dto::class)` or
 * `$form->getValues(Dto::class)`) by either `new Dto()` + `$dto->$field = $value`
 * (no required constructor) or `new Dto(...namedFieldValues)` (required
 * constructor). Reading a typed DTO is covered by PHPStan; the *write* is magic.
 * This rule verifies, from the inferred form shape, that the mapping can succeed:
 * the class is instantiable, each field targets an existing writable property /
 * constructor parameter, and the field's value type fits the target's type.
 *
 * @implements Rule<MethodCall>
 */
final class FormValuesMappedTypeRule implements Rule
{

	private const TRIGGER_METHODS = ['getValues', 'getUntrustedValues', 'getUnsafeValues', 'setMappedType'];

	private bool $enabled;

	private ReflectionProvider $reflectionProvider;

	private ContainerModel $model;

	public function __construct(
		ConfigurationGuard $guard,
		bool $enabled,
		ReflectionProvider $reflectionProvider,
		ContainerModel $model
	)
	{
		$guard->validate();
		$this->enabled = $enabled;
		$this->reflectionProvider = $reflectionProvider;
		$this->model = $model;
	}

	public function getNodeType(): string
	{
		return MethodCall::class;
	}

	/**
	 * @return list<IdentifierRuleError>
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		if (!$this->enabled || !$node->name instanceof Identifier) {
			return [];
		}

		if (!in_array($node->name->toString(), self::TRIGGER_METHODS, true)) {
			return [];
		}

		if ($node->isFirstClassCallable()) {
			return [];
		}

		$args = $node->getArgs();
		if (count($args) < 1) {
			return [];
		}

		$dtoClass = $this->mappedClass($scope->getType($args[0]->value));
		if ($dtoClass === null || !$this->reflectionProvider->hasClass($dtoClass)) {
			return [];
		}

		$shape = $this->model->resolveFormShapeFromExpression($node->var, $scope);
		if ($shape === null) {
			return [];
		}

		return $this->validate($this->reflectionProvider->getClass($dtoClass), $shape, $scope);
	}

	private function mappedClass(Type $argType): ?string
	{
		foreach ($argType->getClassStringObjectType()->getObjectClassNames() as $className) {
			$normalized = ltrim($className, '\\');

			return $normalized === ArrayHash::class || $normalized === stdClass::class
				? null
				: $normalized;
		}

		return null;
	}

	/**
	 * @return list<IdentifierRuleError>
	 */
	private function validate(ClassReflection $dto, FormShape $shape, Scope $scope): array
	{
		if ($dto->isInterface() || $dto->isAbstract()) {
			return [$this->error(
				sprintf('Form values cannot be mapped to %s because it is not instantiable.', $dto->getName()),
			)];
		}

		$valueSlots = [];
		foreach ($shape->getSlots() as $slotName => $slot) {
			if (!$slot->isOmitted()) {
				$valueSlots[] = $slotName;
			}
		}

		$fieldNames = array_merge(
			$valueSlots,
			array_keys($shape->getContainers()),
			array_keys($shape->getReplicators()),
		);

		$constructor = $dto->hasConstructor() ? $dto->getConstructor() : null;
		$params = $constructor === null ? [] : $constructor->getVariants()[0]->getParameters();

		return $this->hasRequiredParameter($params)
			? $this->validateConstructorMapping($dto, $params, $shape, $fieldNames, $scope)
			: $this->validatePropertyMapping($dto, $shape, $fieldNames, $scope);
	}

	/**
	 * @param list<string> $fieldNames
	 * @return list<IdentifierRuleError>
	 */
	private function validatePropertyMapping(
		ClassReflection $dto,
		FormShape $shape,
		array $fieldNames,
		Scope $scope
	): array
	{
		$errors = [];
		foreach ($fieldNames as $name) {
			if (!$dto->hasInstanceProperty($name)) {
				// A field with no declared property is written as a dynamic property. That is fine
				// for a class that permits them — #[AllowDynamicProperties], a __set() handler, or
				// any class under PHP < 8.2 (where the attribute does not exist and dynamic
				// properties are always allowed) — but deprecated since 8.2, a fatal error from 9.0,
				// for an ordinary class. allowsDynamicProperties() captures exactly that per the
				// analysed phpVersion.
				if (!$dto->allowsDynamicProperties()) {
					$errors[] = $this->error(sprintf(
						'Form field %s has no matching property on mapped type %s.',
						$name,
						$dto->getName(),
					));
				}

				continue;
			}

			$property = $dto->getInstanceProperty($name, $scope);
			if (!$property->isPublic()) {
				$errors[] = $this->error(sprintf(
					'Form field %s maps to non-public property %s::$%s, which Nette cannot write to.',
					$name,
					$dto->getName(),
					$name,
				));

				continue;
			}

			if (!$property->isWritable()) {
				$errors[] = $this->error(sprintf(
					'Form field %s maps to read-only property %s::$%s, which Nette cannot write after construction.',
					$name,
					$dto->getName(),
					$name,
				));

				continue;
			}

			$errors = array_merge(
				$errors,
				$this->fieldTypeErrors(
					$name,
					$property->getWritableType(),
					$shape,
					$scope,
					$dto->getName(),
					'property',
				),
			);
		}

		return array_merge($errors, $this->uninitializedPropertyErrors($dto, $fieldNames));
	}

	/**
	 * A typed property with no default is uninitialized unless the form provides a matching
	 * (non-omitted) field; the mapped object is created but reading that property throws
	 * "must not be accessed before initialization". Untyped (defaults to null) and
	 * defaulted/nullable-with-default properties are fine when unmapped.
	 *
	 * @param list<string> $fieldNames
	 * @return list<IdentifierRuleError>
	 */
	private function uninitializedPropertyErrors(ClassReflection $dto, array $fieldNames): array
	{
		$errors = [];
		foreach ($dto->getNativeReflection()->getProperties() as $property) {
			if (
				!$property->isPublic()
				|| $property->isStatic()
				|| !$property->hasType()
				|| $property->hasDefaultValue()
				|| in_array($property->getName(), $fieldNames, true)
			) {
				continue;
			}

			$errors[] = $this->error(sprintf(
				'Mapped type %s has typed property $%s with no default, but the form has no matching field, '
					. 'so it is left uninitialized.',
				$dto->getName(),
				$property->getName(),
			));
		}

		return $errors;
	}

	/**
	 * @param array<int, ParameterReflection> $params
	 * @param list<string> $fieldNames
	 * @return list<IdentifierRuleError>
	 */
	private function validateConstructorMapping(
		ClassReflection $dto,
		array $params,
		FormShape $shape,
		array $fieldNames,
		Scope $scope
	): array
	{
		$errors = [];
		$paramNames = [];
		foreach ($params as $param) {
			$paramNames[] = $param->getName();
			if (!$param->isOptional() && !in_array($param->getName(), $fieldNames, true)) {
				$errors[] = $this->error(sprintf(
					'Mapped type %s requires constructor parameter $%s, but the form has no matching field.',
					$dto->getName(),
					$param->getName(),
				));
			}

			if (in_array($param->getName(), $fieldNames, true)) {
				$errors = array_merge($errors, $this->fieldTypeErrors(
					$param->getName(),
					$param->getType(),
					$shape,
					$scope,
					$dto->getName(),
					'constructor parameter',
				));
			}
		}

		foreach ($fieldNames as $name) {
			if (!in_array($name, $paramNames, true)) {
				$errors[] = $this->error(sprintf(
					'Form field %s has no matching constructor parameter on mapped type %s (it would be passed as an unknown named argument).',
					$name,
					$dto->getName(),
				));
			}
		}

		return $errors;
	}

	/**
	 * A field maps to a property/constructor-parameter target. Slots are checked by
	 * value type; containers and replicators are composite values that Nette writes
	 * recursively — a container whose target is itself a mapped class recurses into
	 * that class (parity with the top level, driven by the target's declared type
	 * exactly as Nette\Forms\Helpers::getSingleType does at runtime); any composite
	 * whose target is a scalar cannot be written at all.
	 *
	 * @return list<IdentifierRuleError>
	 */
	private function fieldTypeErrors(
		string $name,
		Type $targetType,
		FormShape $shape,
		Scope $scope,
		string $dtoName,
		string $targetKind
	): array
	{
		$isContainer = isset($shape->getContainers()[$name]);
		$isReplicator = isset($shape->getReplicators()[$name]);

		if ($isContainer || $isReplicator) {
			if ($isContainer) {
				$nested = $this->singleCustomClass($targetType);
				if ($nested !== null) {
					return $this->validate($nested, $shape->getContainers()[$name], $scope);
				}
			}

			if ($this->isScalarTarget($targetType)) {
				return [$this->error(sprintf(
					'Form %s %s is a composite value, which cannot be written into scalar %s %s of mapped type %s (%s).',
					$isContainer ? 'container' : 'replicator',
					$name,
					$targetKind,
					$name,
					$dtoName,
					$targetType->describe(VerbosityLevel::precise()),
				))];
			}

			if ($isReplicator) {
				$inner = $shape->getReplicators()[$name]->getInner();
				$itemClass = $inner->getMappedType();
				if ($itemClass !== null && $this->reflectionProvider->hasClass($itemClass)) {
					return $this->validate($this->reflectionProvider->getClass($itemClass), $inner, $scope);
				}
			}

			return [];
		}

		$mismatch = $this->typeMismatch(
			$name,
			FormValuesProjector::member($shape, $name),
			$targetType,
			$dtoName,
			$targetKind,
		);

		return $mismatch === null ? [] : [$mismatch];
	}

	private function singleCustomClass(Type $targetType): ?ClassReflection
	{
		$classes = TypeCombinator::removeNull($targetType)->getObjectClassNames();
		if (count($classes) !== 1) {
			return null;
		}

		$className = ltrim($classes[0], '\\');
		if (
			$className === ArrayHash::class
			|| $className === stdClass::class
			|| !$this->reflectionProvider->hasClass($className)
		) {
			return null;
		}

		return $this->reflectionProvider->getClass($className);
	}

	private function isScalarTarget(Type $targetType): bool
	{
		$scalar = new UnionType([new IntegerType(), new FloatType(), new StringType(), new BooleanType()]);

		return $scalar->isSuperTypeOf(TypeCombinator::removeNull($targetType))->yes();
	}

	/**
	 * The write target is the phpdoc-narrowed writable type, not just the native
	 * type. Nette enforces only the native type at runtime, but the DTO's declared
	 * phpdoc type is its contract: if the inferred form value type does not fit it
	 * (e.g. a possibly-empty `string` field written into a `non-empty-string`
	 * property), the mapped object would silently violate that contract, so the rule
	 * reports it. When the inferred field type does fit (e.g. it is itself
	 * `non-empty-string`), no error is raised.
	 */
	private function typeMismatch(
		string $name,
		Type $fieldType,
		Type $targetType,
		string $dtoName,
		string $targetKind
	): ?IdentifierRuleError
	{
		if ($fieldType instanceof ErrorType || $fieldType instanceof MixedType) {
			return null;
		}

		if ($targetType->accepts($fieldType, true)->yes()) {
			return null;
		}

		return $this->error(sprintf(
			'Form field %s has value type %s, which is not accepted by %s %s of mapped type %s (%s).',
			$name,
			$fieldType->describe(VerbosityLevel::precise()),
			$targetKind,
			$name,
			$dtoName,
			$targetType->describe(VerbosityLevel::precise()),
		));
	}

	/**
	 * @param array<int, ParameterReflection> $params
	 */
	private function hasRequiredParameter(array $params): bool
	{
		foreach ($params as $param) {
			if (!$param->isOptional()) {
				return true;
			}
		}

		return false;
	}

	private function error(string $message): IdentifierRuleError
	{
		return RuleErrorBuilder::message($message)
			->identifier('orisaiNette.forms.mappedTypeWrite')
			->build();
	}

}
