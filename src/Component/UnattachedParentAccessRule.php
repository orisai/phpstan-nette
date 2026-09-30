<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Component;

use Nette\InvalidStateException;
use OriPhpstan\Nette\Component\Attachment\ParentAccessors;
use OriPhpstan\Nette\Component\Attachment\ThrowingAccessorCall;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use function sprintf;

/**
 * A parent accessor called on a component that is provably not attached to a parent - a guaranteed
 * `Nette\InvalidStateException`, most often inside a `createComponentX()` factory, where the
 * component being built is attached by the CALLER and nothing before the `return` has a parent yet.
 *
 * The subject is the created component, never `$this`. A container's own chain is usually fine, and
 * a rule that read the container instead would fire on nearly every factory in a project.
 *
 * Two independent proofs have to line up before anything is reported, and each degrades on its own:
 *
 * 1. **the receiver is detached** - DetachedReceiverVisitor tagged the call, which it does only for
 *    a reference whose whole story the walk saw and which no path attached. Anything else, an
 *    escape or two paths disagreeing included, leaves the call untagged;
 * 2. **the accessor throws here** - ThrowingAccessorCall says so: the resolved method is one
 *    ParentAccessors records as reaching `lookup()`, resolved by DECLARING class so Nette's non-throwing overrides
 *    (`Nette\Forms\Form::getForm()`, `Presenter::getPresenter()`) and any project override drop out,
 *    and its `$throw` argument at this call site is true or absent.
 *
 * An unresolvable receiver type, an unreadable `$throw` and an unpacked argument list all report
 * nothing: the whole point of the rule is that a report is a claim that correct-looking code raises,
 * so Maybe has to be silent.
 *
 * @implements Rule<MethodCall>
 */
final class UnattachedParentAccessRule implements Rule
{

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

	public function processNode(Node $node, Scope $scope): array
	{
		if (!$this->enabled) {
			return [];
		}

		if ($node->getAttribute(DetachedReceiverVisitor::AttributeName) !== true) {
			return [];
		}

		if (!$node->name instanceof Node\Identifier) {
			return [];
		}

		$method = $node->name->toString();
		$receiverType = $scope->getType($node->var);
		if (!$receiverType->hasMethod($method)->yes()) {
			return [];
		}

		if (!ThrowingAccessorCall::throwsWhenDetached($receiverType->getMethod($method, $scope), $node, $scope)) {
			return [];
		}

		$error = RuleErrorBuilder::message(sprintf(
			'Component is not attached to a parent here, so %s() always throws %s.',
			$method,
			InvalidStateException::class,
		))
			->identifier('orisai.nette.component.unattachedParentAccess');

		$noThrow = ParentAccessors::noThrowSpelling($method);
		$error->addTip(
			$noThrow === null
				? 'Attach the component first — $container[\'name\'] = $component — and ask afterwards.'
				: sprintf(
					'Attach the component first — $container[\'name\'] = $component — or use %s, which answers null instead of throwing.',
					$noThrow,
				),
		);

		return [$error->build()];
	}

}
