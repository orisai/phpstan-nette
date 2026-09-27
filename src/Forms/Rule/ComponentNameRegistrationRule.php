<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Rule;

use Nette\ComponentModel\Container as ComponentContainer;
use Nette\ComponentModel\IComponent;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Forms\Shape\ComponentPath;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use function count;
use function sprintf;
use function strncmp;

/**
 * The registration-side half of ComponentPath's name check. Every read path already consults
 * ComponentPath::isValidSegment() — a name that cannot exist resolves to nothing there — but the
 * WRITE side said nothing at all, even though `Nette\ComponentModel\Container::addComponent()`
 * applies the identical regexp and THROWS
 * `Nette\InvalidArgumentException: Component name must be non-empty alphanumeric string, '…' given.`
 * `$form->addText('bad name')` is a guaranteed runtime failure on the very first render, and it was
 * silent.
 *
 * Only a name the call site states as a single constant string is judged. A computed one degrades
 * silently: this rule never guesses at a name it cannot read, so a legal dynamic name is never
 * reported, and an illegal one is simply not caught here.
 *
 * A call has to clear three gates together before its argument is even looked at, and all three are
 * load-bearing. The receiver must be a `Nette\ComponentModel\Container` (only a container registers
 * children); the method must have a parameter literally called `name`, which is what excludes
 * `addRule()`, `addCondition()`, `addFilter()`, `addError()` and `addGroup($caption)` without a
 * whitelist and what finds `addComponent()`'s SECOND parameter as readily as a factory's first; and
 * the method must RETURN an `IComponent`, i.e. hand back the thing it just registered.
 *
 * That last gate is the one measurement forced. A parameter called `$name` is not evidence of a
 * component name: `Ublaboo\DataGrid\DataGrid::addAction($key, $name, …)` and its addColumn* siblings
 * call their human-readable COLUMN TITLE `$name`, and DataGrid is a `Nette\Application\UI\Control`,
 * hence a container — without the return-type gate they produced 63 false positives across this
 * project's corpus, on titles like `'ID faktury'`. Ublaboo's columns extend a plain
 * `FilterableColumn`, never `IComponent`, so returning what was registered is exactly the property
 * that separates a real registration from a look-alike. Gating on the DECLARING class instead was
 * tried and rejected: it restricts the rule to Nette's own two container classes and misses every
 * project that wraps `addContainer()`/`addDynamic()` in a trait of its own — the common case here.
 *
 * @implements Rule<MethodCall>
 */
final class ComponentNameRegistrationRule implements Rule
{

	private const NAME_PARAMETER = 'name';

	private bool $enabled;

	public function __construct(ConfigurationGuard $guard, bool $enabled)
	{
		$guard->validate();
		$this->enabled = $enabled;
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
		if (!$this->enabled || !$node->name instanceof Identifier || $node->isFirstClassCallable()) {
			return [];
		}

		$method = $node->name->toString();
		if (strncmp($method, 'add', 3) !== 0) {
			return [];
		}

		$callerType = $scope->getType($node->var);
		if (
			!(new ObjectType(ComponentContainer::class))->isSuperTypeOf($callerType)->yes()
			|| !$callerType->hasMethod($method)->yes()
		) {
			return [];
		}

		$variant = ParametersAcceptorSelector::selectFromArgs(
			$scope,
			$node->getArgs(),
			$callerType->getMethod($method, $scope)->getVariants(),
		);

		if (!(new ObjectType(IComponent::class))->isSuperTypeOf($variant->getReturnType())->yes()) {
			return [];
		}

		$argument = $this->nameArgument($node, $variant);
		if ($argument === null) {
			return [];
		}

		$constantStrings = $scope->getType($argument->value)->getConstantStrings();
		if (count($constantStrings) !== 1) {
			return [];
		}

		$name = $constantStrings[0]->getValue();
		if (ComponentPath::isValidRegistrationName($name)) {
			return [];
		}

		return [
			RuleErrorBuilder::message(sprintf(
				"Component name '%s' is rejected by Nette at registration: addComponent() accepts a "
				. 'non-empty name of [a-zA-Z0-9_] only, and throws otherwise.',
				$name,
			))
				->identifier('orisaiNette.forms.invalidComponentName')
				->build(),
		];
	}

	private function nameArgument(MethodCall $node, ParametersAcceptor $variant): ?Arg
	{
		$position = null;
		foreach ($variant->getParameters() as $index => $parameter) {
			if ($parameter->getName() === self::NAME_PARAMETER) {
				$position = $index;

				break;
			}
		}

		if ($position === null) {
			return null;
		}

		$cursor = 0;
		foreach ($node->getArgs() as $arg) {
			if ($arg->unpack) {
				// A spread makes every following position unknowable; the name may or may not be
				// inside it, so nothing about this call can be judged.
				return null;
			}

			if ($arg->name !== null) {
				if ($arg->name->toString() === self::NAME_PARAMETER) {
					return $arg;
				}

				continue;
			}

			if ($cursor === $position) {
				return $arg;
			}

			$cursor++;
		}

		return null;
	}

}
