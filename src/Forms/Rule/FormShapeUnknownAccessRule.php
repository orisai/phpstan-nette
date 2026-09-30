<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Rule;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Forms\Graph\ExistenceCheckMarkingNodeVisitor;
use OriPhpstan\Nette\Forms\Shape\ComponentPath;
use OriPhpstan\Nette\Forms\Shape\ComponentPathWalk;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use OriPhpstan\Nette\Forms\Shape\UnknownReason;
use OriPhpstan\Nette\Forms\Type\FormReplicatorType;
use OriPhpstan\Nette\Forms\Type\FormShapeType;
use OriPhpstan\Nette\Forms\Type\FormValuesObjectShapeType;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use function array_intersect;
use function array_merge;
use function count;
use function implode;

/**
 * @implements Rule<Expr>
 */
final class FormShapeUnknownAccessRule implements Rule
{

	private bool $enabled;

	public function __construct(ConfigurationGuard $guard, bool $enabled)
	{
		$guard->validate();
		$this->enabled = $enabled;
	}

	public function getNodeType(): string
	{
		return Expr::class;
	}

	public function processNode(Node $node, Scope $scope): array
	{
		if (!$this->enabled) {
			return [];
		}

		if ($node->getAttribute(ExistenceCheckMarkingNodeVisitor::ATTRIBUTE) === true) {
			return [];
		}

		if ($node instanceof PropertyFetch) {
			return $this->processPropertyFetch($node, $scope);
		}

		if ($node instanceof ArrayDimFetch) {
			return $this->processArrayDimFetch($node, $scope);
		}

		if ($node instanceof MethodCall) {
			return $this->processMethodCall($node, $scope);
		}

		return [];
	}

	/**
	 * getComponent($name) and offsetGet($name) are the throwing access — equivalent to
	 * $form[$name] (Nette\ComponentModel\ArrayAccess::offsetGet delegates to getComponent),
	 * so a statically-absent name is reported the same way. getComponent($name, false) is the
	 * no-throw existence check (returns null) and is left alone, like isset($form[$name]).
	 *
	 * @return list<IdentifierRuleError>
	 */
	private function processMethodCall(MethodCall $node, Scope $scope): array
	{
		if (!$node->name instanceof Identifier) {
			return [];
		}

		$method = $node->name->toString();
		if ($method !== 'getComponent' && $method !== 'offsetGet') {
			return [];
		}

		if ($node->isFirstClassCallable()) {
			return [];
		}

		$args = $node->getArgs();
		if (!isset($args[0])) {
			return [];
		}

		if ($method === 'getComponent') {
			$throwArg = $args[1]->value ?? null;
			if ($throwArg !== null && !$scope->getType($throwArg)->isTrue()->yes()) {
				return [];
			}
		}

		$base = $scope->getType($node->var);

		$strings = $scope->getType($args[0]->value)->getConstantStrings();
		if (count($strings) !== 1) {
			return [];
		}

		if ($base instanceof FormReplicatorType) {
			$name = $strings[0]->getValue();

			return $this->classifyReplicatorPath(
				$base->getOwnFormShape(),
				ComponentPath::split($name),
				$node,
				$name,
			);
		}

		if (!$base instanceof FormShapeType) {
			return [];
		}

		return $this->classify($base->getFormShape(), $strings[0]->getValue(), $node, false);
	}

	/** @return list<IdentifierRuleError> */
	private function processPropertyFetch(PropertyFetch $node, Scope $scope): array
	{
		if (!$node->name instanceof Identifier) {
			return [];
		}

		$base = $scope->getType($node->var);
		if (!$base instanceof FormValuesObjectShapeType) {
			return [];
		}

		return $this->classify($base->getFormShape(), $node->name->toString(), $node, true);
	}

	/** @return list<IdentifierRuleError> */
	private function processArrayDimFetch(ArrayDimFetch $node, Scope $scope): array
	{
		if ($node->dim === null) {
			return [];
		}

		$base = $scope->getType($node->var);

		$strings = $scope->getType($node->dim)->getConstantStrings();
		if (count($strings) !== 1) {
			return [];
		}

		$name = $strings[0]->getValue();

		if ($base instanceof FormReplicatorType) {
			return $this->classifyReplicatorPath(
				$base->getOwnFormShape(),
				ComponentPath::split($name),
				$node,
				$name,
			);
		}

		if ($base instanceof FormValuesObjectShapeType) {
			$shape = $base->getFormShape();
			$isValues = true;
		} elseif ($base instanceof FormShapeType) {
			$shape = $base->getFormShape();
			$isValues = false;
		} else {
			return [];
		}

		return $this->classify($shape, $name, $node, $isValues);
	}

	/**
	 * The component-side entry point splits the name and hands it to the shared ComponentPath walk;
	 * the $values side never does, because $values is a data hash rather than a component tree and a
	 * '-' in a field name means nothing there.
	 *
	 * Nette's Container::getComponent() splits a name on IComponent::NameSeparator and descends
	 * recursively as long as each segment is itself a container (vendor/nette/component-model/src/
	 * ComponentModel/Container.php:116), so $form['filter']['rep-addNode'] is the SAME lookup as
	 * $form['filter']['rep']['addNode'] at runtime - a literal, single-segment check on the joined
	 * name would never match and always report noSuchComponent.
	 *
	 * @param string|null $displayName the name to put in the reported message, when it differs from
	 * $name (the lookup key) - a separator path reports the FULL name the user wrote (e.g.
	 * 'rep-addNode'), not just the segment the lookup actually failed on
	 * @return list<IdentifierRuleError>
	 */
	private function classify(
		FormShape $shape,
		string $name,
		Expr $node,
		bool $isValues,
		?string $displayName = null
	): array
	{
		$displayName ??= $name;

		if ($isValues) {
			return $this->classifyLeaf($shape, $name, $node, true, $displayName);
		}

		return $this->classifyPath($shape, ComponentPath::split($name), $node, $displayName);
	}

	/**
	 * One name looked up in one shape, with no path semantics left in it.
	 *
	 * @return list<IdentifierRuleError>
	 */
	private function classifyLeaf(
		FormShape $shape,
		string $name,
		Expr $node,
		bool $isValues,
		string $displayName
	): array
	{
		$slots = $shape->getSlots();
		if (isset($slots[$name])) {
			if (!$slots[$name]->isTypeOpaque()) {
				return [];
			}

			return [
				RuleErrorBuilder::message("Form value '" . $displayName . "' has an unknown type.")
					->identifier('orisai.nette.forms.partiallyUnknown')
					->tip('Form shape opened by: ' . $this->reasons($shape))
					->line($node->getStartLine())
					->build(),
			];
		}

		if (isset($shape->getContainers()[$name]) || isset($shape->getReplicators()[$name])) {
			return [];
		}

		// A control that contributes no VALUE still EXISTS as a component. addSubmit(), addImageButton(),
		// addImage() and addReCaptcha() (given a literal name) are examples that resolve to
		// KIND_OMITTED - so does any CUSTOM add* returning a SubmitButton/ImageButton subclass (see
		// NetteEffectiveControlValueTypeResolver::resolveControlType()) - which CompositionState records
		// into componentTypes and deliberately never into slots - "omitted" names the value side, not
		// the component side. Without this clause every one of those reads as a non-existent component;
		// it surfaced on Kdyby Replicator's removeNode/<name>-addNode buttons only because replicator
		// templates reference those constantly.
		if (!$isValues && isset($shape->getComponentTypes()[$name])) {
			return [];
		}

		if ($shape->getUnknown()->hasUnknown()) {
			// Real, statically-added fields were lost, so the accessed name is most likely one of
			// them; do not flag it. Other unknown reasons (dynamic names, extension/unfollowed
			// calls) stay reported.
			//
			// DERIVED, never re-spelled: this set is LOST_FIELD_UNKNOWN_REASONS plus the
			// unresolved-origin reason this rule alone adds (a prebuilt form whose property-fetch
			// origin is never read). A hand-copied list drifted from the constant twice inside two
			// commits — both times because a reason was added to the constant and not to the copy —
			// so the copy is gone. ResolvedForm::identityKnown() spells the same superset the same
			// way; UNRESOLVED_ORIGIN stays out of the constant because hasLostFieldUnknown() gates
			// shape MERGING, where admitting it would change behaviour.
			$lostFieldReasons = array_merge(
				UnknownReason::LOST_FIELD_UNKNOWN_REASONS,
				[UnknownReason::UNRESOLVED_ORIGIN],
			);
			if (array_intersect($lostFieldReasons, $shape->getUnknown()->getReasons()) !== []) {
				return [];
			}

			return [
				RuleErrorBuilder::message("Form value '" . $displayName . "' may not exist; the form shape is open.")
					->identifier('orisai.nette.forms.unknownAccess')
					->tip('Form shape opened by: ' . $this->reasons($shape))
					->line($node->getStartLine())
					->build(),
			];
		}

		$noun = $isValues ? 'value' : 'component';

		return [
			RuleErrorBuilder::message(
				'Form ' . $noun . " '" . $displayName . "' does not exist.",
			)
				->identifier('orisai.nette.forms.noSuchComponent')
				->line($node->getStartLine())
				->build(),
		];
	}

	/**
	 * Maps the shared ComponentPath walk onto this rule's ABSENCE question. The walk itself is the
	 * same one FormShapeProjector::offsetPath() asks for a TYPE and FormShapeType asks about
	 * DEFINITE existence; only the mapping below is this rule's.
	 *
	 * The two arms that REPORT are the two the walk proves:
	 *
	 * - OUTCOME_MISSING - an intermediate segment in no channel of its container at all. Nette
	 *   throws "Component with name 'x' does not exist" there, and a closed shape proves it, so this
	 *   is classified exactly like a missing leaf (openness and the lost-field reasons still gate
	 *   it). Returning [] here instead was a detection loss: $form['nope-x'] on a closed shape
	 *   reported nothing while $form['nope'] reported, for the one lookup Nette performs identically.
	 * - OUTCOME_INVALID_NAME - a segment Container::addComponent() could never have registered, so
	 *   getComponent() throws whatever the container holds. Unlike every other arm this does not
	 *   depend on the shape being closed.
	 *
	 * OUTCOME_NOT_CONTAINER degrades, per this rule's OPEN discipline: the shape carries no
	 * reflection to prove a control class is not itself an IContainer.
	 *
	 * @param non-empty-list<string> $segments
	 * @return list<IdentifierRuleError>
	 */
	private function classifyPath(FormShape $shape, array $segments, Expr $node, string $displayName): array
	{
		$walk = ComponentPath::walk($shape, $segments);

		$hop = $walk->getReplicatorHop();
		if ($hop !== null) {
			return $this->classifyReplicatorPath($hop[0]->getOwn(), $hop[1], $node, $displayName);
		}

		if ($walk->getOutcome() === ComponentPathWalk::OUTCOME_INVALID_NAME) {
			return [
				RuleErrorBuilder::message(
					"Form component path '" . $displayName . "' has an invalid segment '"
					. $walk->getSegment() . "'; a component name must be a non-empty alphanumeric string.",
				)
					->identifier('orisai.nette.forms.shapeInvalidComponentName')
					->line($node->getStartLine())
					->build(),
			];
		}

		if (
			$walk->isLeaf()
			|| $walk->getOutcome() === ComponentPathWalk::OUTCOME_MISSING
		) {
			return $this->classifyLeaf($walk->getShape(), $walk->getSegment(), $node, false, $displayName);
		}

		return [];
	}

	/**
	 * A path that has reached a replicator - either directly (the accessed expression's own type IS
	 * a FormReplicatorType) or through a '-'-joined segment (classifyPath() above). A purely-decimal
	 * FIRST segment is a dynamically created ROW (Kdyby names each row by its integer index, which
	 * Nette casts to a string key, so `$rep['0-field']` really is row 0's 'field') - unresolvable
	 * statically, so it degrades rather than being misreported as a missing own child. The check is
	 * on the first SEGMENT and not on the whole name: '0-field' is not a decimal string, but the row
	 * it addresses is just as unknowable as plain '0'.
	 *
	 * Anything else names one of the replicator's OWN children (e.g. `$rep->addSubmit('addNode', …)`
	 * on the addDynamic() return value), classified against the own shape like any other path.
	 *
	 * @param non-empty-list<string> $segments
	 * @return list<IdentifierRuleError>
	 */
	private function classifyReplicatorPath(
		FormShape $ownShape,
		array $segments,
		Expr $node,
		string $displayName
	): array
	{
		if (ComponentPath::isRowKey($segments[0])) {
			return [];
		}

		return $this->classifyPath($ownShape, $segments, $node, $displayName);
	}

	private function reasons(FormShape $shape): string
	{
		return implode(',', $shape->getUnknown()->getReasons());
	}

}
