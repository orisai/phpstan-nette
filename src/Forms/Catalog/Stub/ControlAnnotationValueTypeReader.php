<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Catalog\Stub;

use Nette\ComponentModel\IComponent;
use OriPhpstan\Nette\Forms\Catalog\ChoiceModel;
use OriPhpstan\Nette\Forms\Catalog\ComponentClassName;
use OriPhpstan\Nette\Forms\Catalog\FormAddsSpec;
use OriPhpstan\Nette\Forms\Catalog\FormAddsViolation;
use OriPhpstan\Nette\Forms\Catalog\ReplicatorMeta;
use OriPhpstan\Nette\Forms\Catalog\RuleCastType;
use OriPhpstan\Nette\Forms\Catalog\WizardMeta;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StaticType;
use PHPStan\Type\Type;
use function count;
use function explode;
use function in_array;
use function ltrim;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function rtrim;
use function strpos;
use function substr;
use function trim;

final class ControlAnnotationValueTypeReader
{

	public const MODIFIER_NULLABLE = 'nullable';

	public const MODIFIER_REQUIRED = 'required';

	private const TAG = '/@form-read-type\s+(\S+)/';

	private const WRITE_TAG = '/@form-write-type\s+(\S+)/';

	private const WRITE_SPEC_TAG = '/@form-write-spec\s+(\S+)/';

	private const MODIFIER_TAG = '/@form-modifier\s+(\S+)/';

	private const READ_BY_ARG_TAG = '/@form-read-by-arg\s+(.+)/';

	private const READ_BY_ARG_DEFAULT = '*';

	private const RULE_CAST_TAG = '/@form-rule-cast\s+(\S+)\s+(\S+)\s+(\S+)/';

	private const CHOICE_TAG = '/@form-choice\s+(single|multi)\s+(\S+)/';

	private const CHOICE_OPEN_TAG = '/@form-choice-open\s+(\S+)/';

	private const REPLICATOR_TAG = '/@form-replicator\s+(\d+)(?:[^\S\n]+([\\\\\w]+))?/';

	private const DISABLER_TAG = '/@form-disabler\b/';

	private const WIZARD_TAG = '/@form-wizard(?:[^\S\n]+(\w+))?/';

	private const WIZARD_DEFAULT_STEP_PREFIX = 'createStep';

	public const ADDS_TAG_NAME = '@form-adds';

	/**
	 * Unlike every other tag here, the adds tag is REPEATABLE and carries TWO operands, so it is read
	 * in two stages instead of by one matchTagBy(): the occurrence pattern takes the whole tail of
	 * each line the tag opens, and ADDS_OPERANDS then either parses that tail or condemns it. A single
	 * combined pattern could not tell a malformed occurrence from an absent one, and an invalid tag
	 * has to be reported rather than skipped.
	 *
	 * ANCHORED to the start of a docblock line, past the opening delimiter or the leading asterisk,
	 * because that is where a phpdoc tag begins and nowhere else. An unanchored pattern reads a
	 * PROSE MENTION as a declaration, which this file and its neighbours are full of: the rule runs
	 * over every function in the analysed tree, so the extension's own source documenting the tag
	 * reported itself eight times before this anchor existed.
	 */
	private const ADDS_TAG = '~^[^\S\n]*(?:/\*\*+|\*)?[^\S\n]*@form-adds(?![\w-])([^\n]*)~m';

	private const ADDS_OPERANDS = '/^\$(\w+)(?:[^\S\n]+([\\\\\w]+))?$/';

	private const FORMS_CONTAINER = 'Nette\Forms\Container';

	private ReflectionProvider $reflectionProvider;

	private TypeStringResolver $typeStringResolver;

	/** @var list<string> */
	private array $catalogs;

	/**
	 * @param list<string> $catalogs
	 */
	public function __construct(
		ReflectionProvider $reflectionProvider,
		TypeStringResolver $typeStringResolver,
		array $catalogs = []
	)
	{
		$this->reflectionProvider = $reflectionProvider;
		$this->typeStringResolver = $typeStringResolver;
		$this->catalogs = $catalogs;
	}

	/**
	 * @param class-string $builtIn
	 * @return list<ClassReflection>
	 */
	private function catalogsOf(string $builtIn): array
	{
		$catalogs = [];
		foreach ([$builtIn, ...$this->catalogs] as $catalogName) {
			if ($this->reflectionProvider->hasClass($catalogName)) {
				$catalogs[] = $this->reflectionProvider->getClass($catalogName);
			}
		}

		return $catalogs;
	}

	/**
	 * @param class-string $builtIn
	 */
	private function catalogMethodDocComment(string $builtIn, string $methodName): ?string
	{
		foreach ($this->catalogsOf($builtIn) as $catalog) {
			if ($catalog->hasNativeMethod($methodName)) {
				return $catalog->getNativeMethod($methodName)->getDocComment();
			}
		}

		return null;
	}

	/**
	 * @param class-string $builtIn
	 * @return list<string>
	 */
	private function catalogDocComments(string $builtIn): array
	{
		$docComments = [];
		foreach ($this->catalogsOf($builtIn) as $catalog) {
			foreach ($catalog->getNativeReflection()->getMethods() as $method) {
				$doc = $method->getDocComment();
				if ($doc !== false) {
					$docComments[] = $doc;
				}
			}
		}

		return $docComments;
	}

	public function getReflectionProvider(): ReflectionProvider
	{
		return $this->reflectionProvider;
	}

	public function ruleCastType(string $ruleIdentifier): ?RuleCastType
	{
		foreach ($this->catalogDocComments(FormRuleTypeCatalog::class) as $doc) {
			if (preg_match(self::RULE_CAST_TAG, $doc, $m) !== 1) {
				continue;
			}

			if ($m[1] !== $ruleIdentifier) {
				continue;
			}

			return new RuleCastType($this->typeStringResolver->resolve($m[2]), $m[3]);
		}

		return null;
	}

	/**
	 * The value a VENDOR add* method produces.
	 *
	 * Method-keyed, not class-keyed, because the value type genuinely is: addText(), addPassword()
	 * and addEmail() all return a TextInput reading string, while addInteger() returns one reading
	 * int|null and addFloat() one reading float|null — one control class, three value types. The same
	 * split holds for addUpload()/addMultiUpload(), both UploadControl. The catalog is also what
	 * says which vendor methods are value factories at all, as against addContainer()/addSubmit().
	 *
	 * Keyed by NAME rather than resolved through the receiver, which is what makes it independent of
	 * the call site: a project container overriding addText() is still answered by the vendor fact.
	 */
	public function readTypeForAddMethod(string $methodName): ?Type
	{
		$raw = $this->matchTag($this->catalogDocComment($methodName));

		return $raw !== null ? $this->typeStringResolver->resolve($raw) : null;
	}

	/**
	 * The built-in accepted-value spec a vendor add* method's control uses, where it is not the one
	 * its control class implies: addInteger()/addFloat() build a TextInput the ladder would call
	 * `text`, and the spec records what the input really accepts.
	 */
	public function writeSpecForAddMethod(string $methodName): ?string
	{
		return $this->matchTagBy($this->catalogDocComment($methodName), self::WRITE_SPEC_TAG);
	}

	/**
	 * The control class a vendor add* method registers — Nette\Forms\Container's own declared return
	 * type for it, read live. Every one of its factories returns the control it just added, so the
	 * class needs no catalog entry, no tag and no hand-kept table: it IS the vendor declaration.
	 */
	public function controlClassForAddMethod(string $methodName): ?string
	{
		if (!$this->reflectionProvider->hasClass(self::FORMS_CONTAINER)) {
			return null;
		}

		$container = $this->reflectionProvider->getClass(self::FORMS_CONTAINER);
		if (!$container->hasNativeMethod($methodName)) {
			return null;
		}

		return ComponentClassName::of(
			$container->getNativeMethod($methodName)->getVariants()[0]->getReturnType(),
		);
	}

	private function catalogDocComment(string $methodName): ?string
	{
		return $this->catalogMethodDocComment(FormValueTypeCatalog::class, $methodName);
	}

	public function readTypeForClass(string $className): ?Type
	{
		if (!$this->reflectionProvider->hasClass($className)) {
			return null;
		}

		$raw = $this->classTag($this->reflectionProvider->getClass($className));

		return $raw !== null ? $this->typeStringResolver->resolve($raw) : null;
	}

	public function writeTypeForClass(string $className): ?string
	{
		if (!$this->reflectionProvider->hasClass($className)) {
			return null;
		}

		return $this->classTagBy($this->reflectionProvider->getClass($className), self::WRITE_TAG);
	}

	public function choiceModelForClass(string $className): ?ChoiceModel
	{
		if (!$this->reflectionProvider->hasClass($className)) {
			return null;
		}

		$class = $this->reflectionProvider->getClass($className);
		$arity = $this->classTagBy($class, self::CHOICE_TAG, 1);
		$keyDomain = $this->classTagBy($class, self::CHOICE_TAG, 2);
		if ($arity === null || $keyDomain === null) {
			return null;
		}

		$openTag = $this->classTagBy($class, self::CHOICE_OPEN_TAG);

		return new ChoiceModel(
			$arity === 'multi',
			$this->typeStringResolver->resolve($keyDomain),
			$openTag !== null ? $this->typeStringResolver->resolve($openTag) : null,
		);
	}

	public function replicatorMetaForAddMethod(string $methodName): ?ReplicatorMeta
	{
		return $this->replicatorMetaFromDoc(
			$this->catalogMethodDocComment(FormReplicatorCatalog::class, $methodName),
			null,
		);
	}

	public function replicatorMetaForClass(string $className): ?ReplicatorMeta
	{
		if (!$this->reflectionProvider->hasClass($className)) {
			return null;
		}

		$class = $this->reflectionProvider->getClass($className);
		foreach ([$class, ...$class->getParents()] as $r) {
			$doc = $r->getResolvedPhpDoc();
			$meta = $doc === null ? null : $this->replicatorMetaFromDoc($doc->getPhpDocString(), $r->getName());
			if ($meta !== null) {
				return $meta;
			}
		}

		return null;
	}

	/**
	 * The replicator behind an instantiated container type ($form['x'] = new Rep(...), or
	 * addComponent(new Rep(...))): the class (or an ancestor) carries @form-replicator, or it
	 * descends from a container class declared by a catalog add method (e.g. Kdyby's Container).
	 */
	public function replicatorMetaForContainerType(Type $controlType): ?ReplicatorMeta
	{
		foreach ($controlType->getObjectClassNames() as $className) {
			$byClass = $this->replicatorMetaForClass($className);
			if ($byClass !== null) {
				return $byClass;
			}
		}

		foreach ($this->catalogDocComments(FormReplicatorCatalog::class) as $doc) {
			$meta = $this->replicatorMetaFromDoc($doc, null);
			if ($meta === null || $meta->getContainerClass() === null) {
				continue;
			}

			if ((new ObjectType($meta->getContainerClass()))->isSuperTypeOf($controlType)->yes()) {
				return $meta;
			}
		}

		return null;
	}

	private function replicatorMetaFromDoc(?string $docComment, ?string $ownerClass): ?ReplicatorMeta
	{
		if ($docComment === null || preg_match(self::REPLICATOR_TAG, $docComment, $m) !== 1) {
			return null;
		}

		$containerClass = isset($m[2]) ? ltrim($m[2], '\\') : $ownerClass;

		return new ReplicatorMeta((int) $m[1], $containerClass);
	}

	public function wizardMetaForClass(string $className): ?WizardMeta
	{
		if (!$this->reflectionProvider->hasClass($className)) {
			return null;
		}

		$class = $this->reflectionProvider->getClass($className);
		foreach ([$class, ...$class->getParents()] as $r) {
			$doc = $r->getResolvedPhpDoc();
			$meta = $doc === null ? null : $this->wizardMetaFromDoc($doc->getPhpDocString());
			if ($meta !== null) {
				return $meta;
			}
		}

		return null;
	}

	private function wizardMetaFromDoc(?string $docComment): ?WizardMeta
	{
		if ($docComment === null || preg_match(self::WIZARD_TAG, $docComment, $m) !== 1) {
			return null;
		}

		return new WizardMeta($m[1] ?? self::WIZARD_DEFAULT_STEP_PREFIX);
	}

	public function modifierEffectForMethod(string $className, string $methodName): ?string
	{
		if (!$this->reflectionProvider->hasClass($className)) {
			return null;
		}

		$class = $this->reflectionProvider->getClass($className);
		if (!$class->hasNativeMethod($methodName)) {
			return null;
		}

		$effect = $this->matchTagBy($class->getNativeMethod($methodName)->getDocComment(), self::MODIFIER_TAG);

		return in_array($effect, [self::MODIFIER_NULLABLE, self::MODIFIER_REQUIRED], true) ? $effect : null;
	}

	/**
	 * A @form-disabler method (e.g. one looping setDisabled() over getComponents(true)):
	 * calling it on a container disables — and so omits from getValues() — every descendant.
	 */
	public function isFormDisabler(string $className, string $methodName): bool
	{
		if (!$this->reflectionProvider->hasClass($className)) {
			return false;
		}

		$class = $this->reflectionProvider->getClass($className);
		if (!$class->hasNativeMethod($methodName)) {
			return false;
		}

		$doc = $class->getNativeMethod($methodName)->getDocComment();

		return $doc !== null && preg_match(self::DISABLER_TAG, $doc) === 1;
	}

	public function isReadByArgModifier(string $controlClass, string $methodName): bool
	{
		return $this->readByArgTag($controlClass, $methodName) !== null;
	}

	public function readTypeByArg(string $controlClass, string $methodName, ?string $argLiteral): ?Type
	{
		$raw = $this->readByArgTag($controlClass, $methodName);
		if ($raw === null) {
			return null;
		}

		if ($argLiteral === null) {
			return new MixedType();
		}

		$raw = rtrim(preg_replace('~\*/\s*$~', '', $raw) ?? $raw);

		$default = null;
		foreach (explode(';', $raw) as $entry) {
			$pair = explode('=', $entry, 2);
			if (count($pair) !== 2) {
				continue;
			}

			$key = trim($pair[0]);
			$typeString = trim($pair[1]);
			if ($key === self::READ_BY_ARG_DEFAULT) {
				$default = $typeString;

				continue;
			}

			if ($this->matchKeyLiteral($key) === $argLiteral) {
				return $this->typeStringResolver->resolve($typeString);
			}
		}

		return $default !== null ? $this->typeStringResolver->resolve($default) : new MixedType();
	}

	private function readByArgTag(string $controlClass, string $methodName): ?string
	{
		$native = $this->matchTagBy(
			$this->catalogMethodDocComment(FormModifierCatalog::class, $methodName),
			self::READ_BY_ARG_TAG,
		);
		if ($native !== null) {
			return $native;
		}

		if (!$this->reflectionProvider->hasClass($controlClass)) {
			return null;
		}

		$class = $this->reflectionProvider->getClass($controlClass);
		if (!$class->hasNativeMethod($methodName)) {
			return null;
		}

		return $this->matchTagBy($class->getNativeMethod($methodName)->getDocComment(), self::READ_BY_ARG_TAG);
	}

	private function matchKeyLiteral(string $key): ?string
	{
		$pos = strpos($key, '::');
		if ($pos === false) {
			return $key;
		}

		$className = ltrim((string) substr($key, 0, $pos), '\\');
		$constName = (string) substr($key, $pos + 2);
		if (!$this->reflectionProvider->hasClass($className)) {
			return null;
		}

		$class = $this->reflectionProvider->getClass($className);
		if (!$class->hasConstant($constName)) {
			return null;
		}

		$strings = $class->getConstant($constName)->getValueType()->getConstantStrings();

		return count($strings) === 1 ? $strings[0]->getValue() : null;
	}

	/**
	 * Whether a docblock OPENS the adds tag on a line of its own, as opposed to merely naming it in
	 * prose. The rule asks this before reporting anything about a node it cannot hand to the reader
	 * (a free function, a closure), so that a docblock discussing the tag is not condemned for
	 * carrying it.
	 */
	public function declaresAdds(?string $docComment): bool
	{
		return $this->matchAllTagBy($docComment, self::ADDS_TAG) !== [];
	}

	/**
	 * The adds-tag occurrences on $method that survived validation, read off the docblock
	 * reflection resolves — so a tag written on a base class governs an override that inherits it,
	 * and resolves against the OVERRIDE's own parameter list.
	 *
	 * @return list<FormAddsSpec>
	 */
	public function addsSpecsForMethod(MethodReflection $method): array
	{
		return $this->analyseAddsTag($method->getDocComment(), $method, true)['specs'];
	}

	/**
	 * The same question asked of a CLASS and a method name rather than of a reflection the caller
	 * already holds — what the walk has when it meets a plain statement instead of a tagged node.
	 * Only occurrences that survived validation count, so an invalid tag stays inert here exactly as
	 * it is everywhere else, and the rule reporting it is the one thing that happens.
	 */
	public function declaresAddsOn(string $className, string $methodName): bool
	{
		if (!$this->reflectionProvider->hasClass($className)) {
			return false;
		}

		$class = $this->reflectionProvider->getClass($className);

		return $class->hasNativeMethod($methodName)
			&& $this->addsSpecsForMethod($class->getNativeMethod($methodName)) !== [];
	}

	/**
	 * Why each rejected occurrence was rejected. Takes the docblock explicitly so the rule can pass
	 * the one the DECLARATION carries: an inherited tag is then reported once, where it is written,
	 * rather than again on every override.
	 *
	 * $enforceContainerScope is off for a TRAIT, whose method is a fragment of whatever uses it: at
	 * the declaration there is no receiver to gate on, and the model gates the using class instead.
	 * Every other check still applies, the trait's own parameter list and return type being its own.
	 *
	 * @return list<FormAddsViolation>
	 */
	public function addsViolationsForMethod(
		?string $docComment,
		MethodReflection $method,
		bool $enforceContainerScope = true
	): array
	{
		return $this->analyseAddsTag($docComment, $method, $enforceContainerScope)['violations'];
	}

	/**
	 * The whole @form-adds contract of one method, in one pass: what the model may use, and what the
	 * rule must report. Both answers come from here so an occurrence can never be honoured by one and
	 * condemned by the other, and every occurrence the model drops leaves a diagnostic behind.
	 *
	 * @return array{specs: list<FormAddsSpec>, violations: list<FormAddsViolation>}
	 */
	private function analyseAddsTag(
		?string $docComment,
		MethodReflection $method,
		bool $enforceContainerScope
	): array
	{
		if ($docComment === null || strpos($docComment, self::ADDS_TAG_NAME) === false) {
			return ['specs' => [], 'violations' => []];
		}

		$occurrences = $this->matchAllTagBy($docComment, self::ADDS_TAG);
		if ($occurrences === []) {
			return ['specs' => [], 'violations' => []];
		}

		$declaringClass = $method->getDeclaringClass()->getName();
		$origin = $declaringClass . '::' . $method->getName() . '()';

		if (
			$enforceContainerScope
			&& !(new ObjectType(self::FORMS_CONTAINER))->isSuperTypeOf(new ObjectType($declaringClass))->yes()
		) {
			return [
				'specs' => [],
				'violations' => [
					new FormAddsViolation(
						'orisai.nette.forms.outsideContainer',
						self::ADDS_TAG_NAME . ' is only valid on a method declared on a '
							. self::FORMS_CONTAINER . ' subclass, and ' . $origin . ' is not declared on one.',
					),
				],
			];
		}

		$variant = $method->getVariants()[0];

		$indexes = [];
		foreach ($variant->getParameters() as $index => $parameter) {
			$indexes[$parameter->getName()] = $index;
		}

		$contract = $this->declaredControlContract($variant->getReturnType(), $declaringClass);

		$specs = [];
		$violations = [];
		$seen = [];

		foreach ($occurrences as $occurrence) {
			$operands = trim((string) preg_replace('~\*/\s*$~', '', $occurrence));
			if (preg_match(self::ADDS_OPERANDS, $operands, $m) !== 1) {
				$violations[] = new FormAddsViolation(
					'orisai.nette.forms.malformed',
					'Malformed ' . self::ADDS_TAG_NAME . ' tag on ' . $origin
						. '; the grammar is ' . self::ADDS_TAG_NAME . ' $parameterName [FullyQualifiedControlClass].',
				);

				continue;
			}

			$parameterName = $m[1];
			$controlClass = ($m[2] ?? '') !== '' ? ltrim($m[2], '\\') : null;
			$valid = true;

			if (!isset($indexes[$parameterName])) {
				$violations[] = new FormAddsViolation(
					'orisai.nette.forms.unknownParameter',
					self::ADDS_TAG_NAME . ' names $' . $parameterName
						. ', which is not a parameter of ' . $origin . '.',
				);
				$valid = false;
			} elseif (isset($seen[$parameterName])) {
				$violations[] = new FormAddsViolation(
					'orisai.nette.forms.duplicateParameter',
					self::ADDS_TAG_NAME . ' names $' . $parameterName . ' more than once on ' . $origin
						. '; each occurrence must name a distinct parameter.',
				);
				$valid = false;
			}

			$seen[$parameterName] = true;

			foreach ($this->controlClassViolations($controlClass, $contract, $origin) as $violation) {
				$violations[] = $violation;
				$valid = false;
			}

			if ($valid) {
				// The class operand and the declared return type are two SOURCES of one thing, so the
				// spec carries whichever supplied it and the model never sees the difference. Filling
				// it here rather than leaving null for the caller to rediscover is what keeps
				// `@form-adds $name` on `: TextInput` resolving identically to the same tag spelling
				// that class out; leaving it null made the explicit spelling resolve BETTER, which is
				// a difference the tag does not mean to express.
				$specs[] = new FormAddsSpec(
					$parameterName,
					$indexes[$parameterName],
					$controlClass ?? $contract['class'],
				);
			}
		}

		return ['specs' => $specs, 'violations' => $violations];
	}

	/**
	 * @param array{class: string|null, forbidsOmission: bool} $contract
	 * @return list<FormAddsViolation>
	 */
	private function controlClassViolations(?string $controlClass, array $contract, string $origin): array
	{
		if ($controlClass === null) {
			if (!$contract['forbidsOmission']) {
				return [];
			}

			return [
				new FormAddsViolation(
					'orisai.nette.forms.missingControlClass',
					self::ADDS_TAG_NAME . ' on ' . $origin . ' names no control class and the declared '
						. 'return type names none either, so the tag has no class to register.',
				),
			];
		}

		if (!$this->reflectionProvider->hasClass($controlClass)) {
			return [
				new FormAddsViolation(
					'orisai.nette.forms.unknownControlClass',
					self::ADDS_TAG_NAME . ' on ' . $origin . ' names control class ' . $controlClass
						. ', which does not exist; write it as a fully qualified name.',
				),
			];
		}

		if (!ComponentClassName::isComponent($controlClass)) {
			return [
				new FormAddsViolation(
					'orisai.nette.forms.invalidControlClass',
					self::ADDS_TAG_NAME . ' on ' . $origin . ' names control class ' . $controlClass
						. ', which is not a ' . IComponent::class . ' and cannot be modelled as a form component.',
				),
			];
		}

		$declared = $contract['class'];
		if ($declared !== null && !(new ObjectType($declared))->isSuperTypeOf(new ObjectType($controlClass))->yes()) {
			return [
				new FormAddsViolation(
					'orisai.nette.forms.returnTypeContradiction',
					self::ADDS_TAG_NAME . ' on ' . $origin . ' names control class ' . $controlClass
						. ', which is not a subtype of the declared return type ' . $declared . '.',
				),
			];
		}

		return [];
	}

	/**
	 * What the DECLARED return type contributes: the control class it names, and whether omitting the
	 * tag's own class leaves nothing behind.
	 *
	 * A return of the receiver itself contributes no control class — a fluent adder returning static
	 * (or the container it is declared on) states nothing about what it registered, which is exactly
	 * the case the optional class operand exists for. An UNDECLARED return contributes no class
	 * either, but the body read can still supply one, so omission stays legal there; a void, null or
	 * scalar return can never yield one, and omitting the class there makes the tag inert.
	 *
	 * @return array{class: string|null, forbidsOmission: bool}
	 */
	private function declaredControlContract(Type $returnType, string $declaringClass): array
	{
		$declared = ComponentClassName::of($returnType);
		$returnsReceiver = $returnType instanceof StaticType
			|| ($declared !== null
				&& (new ObjectType($declared))->isSuperTypeOf(new ObjectType($declaringClass))->yes());
		$class = $returnsReceiver ? null : $declared;

		return [
			'class' => $class,
			'forbidsOmission' => $returnType->isVoid()->yes()
				|| $returnType->isNull()->yes()
				|| $returnType->isScalar()->yes()
				|| ($class === null && $returnType->getObjectClassNames() !== []),
		];
	}

	private function classTag(ClassReflection $reflection): ?string
	{
		return $this->classTagBy($reflection, self::TAG);
	}

	private function classTagBy(ClassReflection $reflection, string $pattern, int $group = 1): ?string
	{
		foreach ([$reflection, ...$reflection->getParents()] as $r) {
			$doc = $r->getResolvedPhpDoc();
			if ($doc === null) {
				continue;
			}

			$raw = $this->matchTagBy($doc->getPhpDocString(), $pattern, $group);
			if ($raw !== null) {
				return $raw;
			}
		}

		return null;
	}

	private function matchTag(?string $docComment): ?string
	{
		return $this->matchTagBy($docComment, self::TAG);
	}

	private function matchTagBy(?string $docComment, string $pattern, int $group = 1): ?string
	{
		if ($docComment === null) {
			return null;
		}

		return preg_match($pattern, $docComment, $m) === 1 ? $m[$group] : null;
	}

	/**
	 * The repeatable sibling of matchTagBy(): every occurrence's captured group instead of the first
	 * one's. Kept beside it rather than folded into it because every other tag here is single — a
	 * second occurrence of @form-read-type is a contradiction, not a list.
	 *
	 * @return list<string>
	 */
	private function matchAllTagBy(?string $docComment, string $pattern, int $group = 1): array
	{
		if ($docComment === null || preg_match_all($pattern, $docComment, $matches) === 0) {
			return [];
		}

		return $matches[$group];
	}

}
