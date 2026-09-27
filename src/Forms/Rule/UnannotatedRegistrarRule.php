<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Rule;

use Nette\Forms\Container as NetteContainer;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Forms\Catalog\Stub\ControlAnnotationValueTypeReader;
use OriPhpstan\Nette\Forms\Component\AnalysedPaths;
use OriPhpstan\Nette\Forms\Graph\ContainerRegistrationDetector;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * A generic adder in ANALYSED code that has not declared what it adds.
 *
 * Nothing is wrong with the shape this reports on: the body is walked, so the registration resolves
 * today whether or not the tag is there, and this rule opens nothing. What it reports is
 * forward-looking — the registration is visible only for as long as the declaration stays inside the
 * analysed paths, and the day the class is extracted into a package the walk loses it with no
 * diagnostic at all. The tag is the fix, and it costs one line.
 *
 * Which methods it may ask that of is the whole difficulty, and the answer is: only those a tag could
 * actually describe. `@form-adds` says "this parameter carries the component's name", so a method
 * registering exactly one component under one of its own parameters is annotatable and a method
 * registering several, or under a literal name, or on some paths only, is not — extraction loses
 * information there too, but no tag could have carried it, so asking for one would be noise. Neither
 * finality nor visibility is consulted: both were tried and say nothing, a specific form being as
 * often non-final and public as a generic one.
 *
 * The analysed/vendor line is drawn from the injected config paths with realpath containment, never
 * from a `vendor/` spelling: it is the same universe every other gate in this extension uses, and
 * being a property of the project rather than of the invocation is what keeps a single-file run's
 * answer equal to a whole-project one.
 *
 * @implements Rule<ClassMethod>
 */
final class UnannotatedRegistrarRule implements Rule
{

	private bool $enabled;

	private AnalysedPaths $analysedPaths;

	private ControlAnnotationValueTypeReader $reader;

	private ContainerRegistrationDetector $detector;

	/**
	 * @param list<string> $analysedPaths
	 */
	public function __construct(
		ConfigurationGuard $guard,
		bool $enabled,
		array $analysedPaths,
		ControlAnnotationValueTypeReader $reader
	)
	{
		$guard->validate();
		$this->enabled = $guard->isFormsEnabled() && $enabled;
		$this->analysedPaths = new AnalysedPaths($analysedPaths);
		$this->reader = $reader;
		$this->detector = new ContainerRegistrationDetector();
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
		if (!$this->enabled || !$this->analysedPaths->isAnalysed($scope->getFile())) {
			return [];
		}

		$classReflection = $scope->isInClass() ? $scope->getClassReflection() : null;
		// A trait declares no receiver, so `$this->addText(...)` in its body registers on whatever
		// class uses it — which this declaration cannot name, and the container gate cannot be applied
		// to. The using class's own methods are still reported.
		if ($classReflection === null || $classReflection->isTrait()) {
			return [];
		}

		if (!(new ObjectType(NetteContainer::class))->isSuperTypeOf(
			new ObjectType($classReflection->getName()),
		)->yes()) {
			return [];
		}

		$registration = ContainerRegistrationDetector::annotatableRegistration(
			$this->detector->registrations($node->stmts, ContainerRegistrationDetector::parameterIndexes($node)),
		);
		if ($registration === null) {
			return [];
		}

		$methodName = $node->name->toString();
		if (!$classReflection->hasNativeMethod($methodName)) {
			return [];
		}

		$parameter = $registration->getNameParameter();
		if ($this->alreadyDeclared($node, $classReflection->getNativeMethod($methodName), $parameter)) {
			return [];
		}

		// The DECLARING entity, not the class the body is being analysed in the context of. A trait body
		// is analysed once per using class, so naming the using class produces one finding per user of
		// a method written once — four of them for this repo's own shared container trait. Naming the
		// trait makes those four one error, which is also where the tag would be written.
		$trait = $scope->getTraitReflection();
		$origin = ($trait !== null ? $trait->getName() : $classReflection->getName()) . '::' . $methodName . '()';

		return [
			RuleErrorBuilder::message(
				$origin . ' registers one component under $'
					. $parameter . ' and does not declare it with '
					. ControlAnnotationValueTypeReader::ADDS_TAG_NAME . ' $' . $parameter
					. '; the registration is then visible only while this class stays inside the analysed paths.',
			)
				->identifier('orisaiNette.forms.unannotatedRegistrar')
				->line($node->getStartLine())
				->build(),
		];
	}

	/**
	 * Two questions, because they fail differently. A tag written HERE silences the rule whatever it
	 * says: an occurrence the reader refuses is FormAddsAnnotationRule's to report, and reporting it
	 * twice would ask an author to add a tag they have already written. An INHERITED tag naming this
	 * parameter silences it too, that being the whole point of validating a declaration once — but only
	 * a valid one reaches here, an invalid one having been reported where it was written.
	 */
	private function alreadyDeclared(ClassMethod $node, MethodReflection $method, ?string $parameter): bool
	{
		$own = $node->getDocComment();
		if ($this->reader->declaresAdds($own === null ? null : $own->getText())) {
			return true;
		}

		foreach ($this->reader->addsSpecsForMethod($method) as $spec) {
			if ($spec->getParameterName() === $parameter) {
				return true;
			}
		}

		return false;
	}

}
