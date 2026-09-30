<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Rule;

use OriPhpstan\Nette\Component\Attachment\ContainerLazyRead;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Forms\Shape\ComponentPath;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use OriPhpstan\Nette\Forms\Type\FormReplicatorType;
use OriPhpstan\Nette\Forms\Type\FormShapeType;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Isset_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;
use function array_reverse;
use function count;

/**
 * `isset($form['x'])` whose answer the shape already knows.
 *
 * `Nette\ComponentModel\ArrayAccess::offsetExists()` is `getComponent($name, false) !== null`, so it
 * runs the same lazy create-and-attach block every read does. Two consequences, and the second is
 * the one that is easy to invert:
 *
 * - a child the shape holds makes the check constantly TRUE, as it would for a plain array;
 * - **a `createComponentX` factory ALSO makes it constantly TRUE**, because the check creates and
 *   attaches the child as a side effect. The interesting always-FALSE case is therefore
 *   *neither held nor buildable*, not "not held".
 *
 * `ContainerLazyRead::holdsChildAfterRead()` owns that table; this rule supplies the two inputs it
 * needs and nothing else. The presence comes from `ComponentPath::childPresence()`, whose NEVER is
 * the same closed-and-absent proof `FormShapeUnknownAccessRule` reports absence from - so the two
 * rules can never disagree about a name, and they can never both speak about one either:
 * `ExistenceCheckMarkingNodeVisitor` marks an isset chain precisely so the absence rule stays
 * silent on it, and this rule reports only what that visitor marked.
 *
 * A Maybe is silent, as is a name the shape cannot walk, an unresolvable receiver and a
 * non-constant offset.
 *
 * @implements Rule<Expr>
 */
final class FormExistenceCheckConstantRule implements Rule
{

	private const OFFSET_EXISTS = 'offsetExists';

	private bool $enabled;

	private ReflectionProvider $reflectionProvider;

	public function __construct(ConfigurationGuard $guard, bool $enabled, ReflectionProvider $reflectionProvider)
	{
		$guard->validate();
		$this->enabled = $enabled;
		$this->reflectionProvider = $reflectionProvider;
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

		if ($node instanceof Isset_) {
			$errors = [];
			foreach ($node->vars as $var) {
				if (!$var instanceof ArrayDimFetch || $var->dim === null) {
					continue;
				}

				foreach ($this->classifyChain($var->var, $var->dim, $var, 'isset()', $scope) as $error) {
					$errors[] = $error;
				}
			}

			return $errors;
		}

		if (
			!$node instanceof MethodCall
			|| $node->isFirstClassCallable()
			|| !$node->name instanceof Identifier
			|| $node->name->toString() !== self::OFFSET_EXISTS
		) {
			return [];
		}

		$args = $node->getArgs();
		if (!isset($args[0]) || $args[0]->unpack) {
			return [];
		}

		return $this->classifyChain($node->var, $args[0]->value, $node, 'offsetExists()', $scope);
	}

	/**
	 * The whole offset chain the check walks, not the last hop alone.
	 *
	 * `isset($form['outer']['x'])` short-circuits: PHP asks the outer container for 'outer' first and
	 * answers false without ever looking for 'x' if it is not there. The two directions therefore
	 * need different amounts of the chain:
	 *
	 * - **never** needs ONE hop to be provably absent. Every hop above it is then unreachable and
	 *   every hop below it is irrelevant - the check is false whether or not the rest resolves - so
	 *   the innermost proven-absent hop is both the proof and the name worth reporting.
	 * - **always** needs EVERY hop to be provably present. A hop this cannot prove leaves the check
	 *   able to answer false, and reporting the last hop alone would call an outer guard redundant on
	 *   the strength of what is inside the container it is guarding. That is the shape the corpus
	 *   actually contains (`isset($form['group_action']['submit'])`), so it is the shape that decides
	 *   the design.
	 *
	 * @return list<IdentifierRuleError>
	 */
	private function classifyChain(
		Expr $receiver,
		Expr $offset,
		Expr $reported,
		string $spelling,
		Scope $scope
	): array
	{
		$hops = [[$receiver, $offset]];
		$inner = $receiver;
		while ($inner instanceof ArrayDimFetch && $inner->dim !== null) {
			$hops[] = [$inner->var, $inner->dim];
			$inner = $inner->var;
		}

		$everyHopHeld = true;
		$outermostName = null;
		foreach (array_reverse($hops) as $index => $hop) {
			$judged = $this->classify($hop[0], $hop[1], $scope);
			if ($judged === null) {
				$everyHopHeld = false;

				continue;
			}

			if ($judged[1] === Certainty::NEVER) {
				return $this->report($judged[0], $spelling, false, $reported);
			}

			$everyHopHeld = $everyHopHeld && $judged[1] === Certainty::HAPPENS;

			if ($index === count($hops) - 1) {
				$outermostName = $judged[0];
			}
		}

		return $everyHopHeld && $outermostName !== null
			? $this->report($outermostName, $spelling, true, $reported)
			: [];
	}

	/**
	 * @return list<IdentifierRuleError>
	 */
	private function report(string $name, string $spelling, bool $exists, Expr $reported): array
	{
		if ($exists) {
			return [
				RuleErrorBuilder::message("Form component '$name' in $spelling always exists.")
					->identifier('orisai.nette.forms.constantExistenceCheck')
					->line($reported->getStartLine())
					->build(),
			];
		}

		$factory = ContainerLazyRead::factoryMethodFor($name);

		return [
			RuleErrorBuilder::message("Form component '$name' in $spelling never exists.")
				->identifier('orisai.nette.forms.constantExistenceCheck')
				->tip(
					$factory === null
						? 'Nette resolves no factory for a capitalised name, so the check cannot build one either.'
						: "The form declares no $factory(), so the check cannot build one either.",
				)
				->line($reported->getStartLine())
				->build(),
		];
	}

	/**
	 * One hop: the name it looks for and the Certainty that the receiver holds it once the check has
	 * run. Null where the hop cannot be judged at all.
	 *
	 * @return array{string, string}|null
	 */
	private function classify(Expr $receiver, Expr $offset, Scope $scope): ?array
	{
		$receiverType = $scope->getType($receiver);
		$shape = $this->shapeOf($receiverType);
		if ($shape === null) {
			return null;
		}

		$strings = $scope->getType($offset)->getConstantStrings();
		if (count($strings) !== 1) {
			return null;
		}

		$name = $strings[0]->getValue();

		// Component-path semantics stay in ComponentPath: a name it will not walk as one segment is
		// one this rule has no presence for, so it is refused rather than guessed at.
		if (!ComponentPath::isValidSegment($name)) {
			return null;
		}

		$declaresFactory = $this->declaresComponentFactory($receiverType, $name);
		if ($declaresFactory === null) {
			return null;
		}

		return [
			$name,
			ContainerLazyRead::holdsChildAfterRead(
				$name,
				ComponentPath::childPresence($shape, $name),
				$declaresFactory,
			),
		];
	}

	private function shapeOf(Type $receiverType): ?FormShape
	{
		if ($receiverType instanceof FormReplicatorType) {
			return $receiverType->getOwnFormShape();
		}

		return $receiverType instanceof FormShapeType ? $receiverType->getFormShape() : null;
	}

	/**
	 * Whether the receiver declares Nette's factory for the name; null where the class cannot be
	 * resolved and neither answer is available.
	 *
	 * The walk asks the same question and resolves an unreadable class to TRUE, because there the
	 * true answer keeps the call descendable and a descent degrades. Here TRUE is the answer that
	 * CLAIMS - a declared factory makes the check constantly true - so the same shortcut would
	 * report a constant condition off a class nobody read. The two directions are opposite for the
	 * same reason: whichever answer degrades is the safe one, and which that is depends on what the
	 * consumer does with it.
	 */
	private function declaresComponentFactory(Type $receiverType, string $childName): ?bool
	{
		$factory = ContainerLazyRead::factoryMethodFor($childName);
		if ($factory === null) {
			// ucfirst($name) === $name: Nette consults no factory at all, whatever the class holds.
			return false;
		}

		$classNames = $receiverType->getObjectClassNames();
		if (count($classNames) !== 1 || !$this->reflectionProvider->hasClass($classNames[0])) {
			return null;
		}

		return $this->reflectionProvider->getClass($classNames[0])->hasNativeMethod($factory);
	}

}
