<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert;

use Nette\Application\UI\Form;
use Nette\Forms\Controls\TextInput;
use function OriPhpstan\Nette\Forms\Testing\assertComponent;

class RegisteringMethodForm extends Form
{

	public function buildEverything(): void
	{
		$this->addText('alpha');
		$this->addCheckbox('beta');
	}

	public function configureOnly(): void
	{
		$this->setAction('/somewhere');
	}

	public function buildUnderAParameterName(string $name): void
	{
		$this->addText($name);
	}

	public function swapOne(): void
	{
		$this->removeComponent($this['gamma']);
		$this->addText('delta');
	}

	public function buildThroughAHelper(): void
	{
		$this->buildEverything();
	}

	public function buildOnlyWhen(bool $when): void
	{
		if ($when) {
			$this->addText('epsilon');
		}
	}

}

class RegisteringMethodFactoryForm extends Form
{

	protected function createComponentSub(): TextInput
	{
		return new TextInput();
	}

}

/**
 * A build method called ON the tracked form — `$form->buildEverything()`, declared on the form and
 * registering into `$this`. It used to fold in nothing and leave the shape CLOSED, so every control
 * it registered was proven absent; the sibling spellings (the same builder handed the form as an
 * ARGUMENT, and one called from the form's own constructor) were both already resolved.
 *
 * What bounds the descent is the callee's OWN BODY: a call is followed only when
 * ContainerRegistrationDetector reads a registration on `$this` there. Every other call on a tracked
 * form — a setter, an accessor, any vendor method that merely reads the component tree, a body that
 * cannot be read at all — leaves the state exactly as it found it, which is what the closed
 * assertions below are for. The rule is deliberately not "a method call on the form opens the shape":
 * that would delete absence reporting wholesale on the strength of ignorance.
 *
 * The one body that registers and must still not be followed is `Container::getComponent()`, which
 * creates and attaches inside an `if`, and which is the very read the absence rule judges: following
 * it downgrades a correct `$form->getComponent('nope')` report to "may not exist". That used to be
 * kept out by demanding a registration every path through the callee reaches — a bar that excluded it
 * for the wrong reason and took honest conditional builders with it. It is now decided per CALL from
 * the two conditions the vendor body states: an already-attached name skips the block, and a receiver
 * declaring no `createComponent<Ucname>` reaches a null component and adds nothing.
 */
final class RegisteringMethodOnTracked
{

	public function builtByAMethodOnTheForm(): void
	{
		$form = new RegisteringMethodForm();
		$form->addText('gamma');
		$form->buildEverything();

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert\RegisteringMethodForm{
			  alpha: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  beta: Nette\Forms\Controls\Checkbox<bool|float|int|string|null, bool>,
			  gamma: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	/**
	 * The bound, stated as a project method: a body that registers nothing is not descended into and
	 * the shape stays CLOSED, so `$form['anything']` is still reported.
	 */
	public function aMethodRegisteringNothingLeavesTheShapeClosed(): void
	{
		$form = new RegisteringMethodForm();
		$form->addText('gamma');
		$form->configureOnly();

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert\RegisteringMethodForm{
			  gamma: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	/**
	 * The same bound on the vendor surface, which is the half that decides whether absence reporting
	 * survives at all: Nette's own readers and setters register nothing, so none of them is followed.
	 */
	public function aVendorSetterLeavesTheShapeClosed(): void
	{
		$form = new RegisteringMethodForm();
		$form->addText('gamma');
		$form->setDefaults([]);
		$form->setMethod('POST');

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert\RegisteringMethodForm{
			  gamma: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	/**
	 * The descent resolves what it can and degrades on the rest by its own rules: a registration under
	 * a name the callee's body does not state opens the shape rather than inventing the caller's
	 * argument as the component's name.
	 */
	public function aParameterNamedRegistrationOpens(): void
	{
		$form = new RegisteringMethodForm();
		$form->addText('gamma');
		$form->buildUnderAParameterName('delta');

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert\RegisteringMethodForm{
			  gamma: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT);
	}

	/**
	 * A callee that also SUBTRACTS is refused outright, exactly as one taking the form as an argument
	 * is: a contribution is folded in additively, so a removal has no representation in it.
	 */
	public function aSubtractingBuilderIsNotFollowed(): void
	{
		$form = new RegisteringMethodForm();
		$form->addText('gamma');
		$form->swapOne();

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert\RegisteringMethodForm{
			  gamma: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT);
	}

	/**
	 * A builder whose every registration sits inside a branch. This used to be refused and pinned as a
	 * residual — `epsilon` was missing from a CLOSED shape, i.e. proven absent, about a control the
	 * callee plainly adds — because the gate demanded a registration every path reaches. It never
	 * needed to: the descent does not assume the branch ran, it walks the callee and meets presences at
	 * the callee's own joins, so `epsilon` comes back MAYBE and reads neither resolve to a proof nor
	 * report an absence.
	 */
	public function aConditionalOnlyBuilderIsFollowed(): void
	{
		$form = new RegisteringMethodForm();
		$form->addText('gamma');
		$form->buildOnlyWhen(true);

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert\RegisteringMethodForm{
			  epsilon?: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  gamma: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	/**
	 * What the every-path bar was really spelling, now derived per call. `Container::getComponent()`
	 * DOES register — through `createComponent()`, inside the `if` guarding a name that is not there
	 * yet — so the loosened bar admits its body; what refuses this call is the state, which proves the
	 * read attaches nothing. `nope` is absent from a closed shape and the form declares no
	 * `createComponentNope`, so `createComponent()` returns null and the call throws instead of
	 * creating. The shape stays closed, which is what keeps the read reportable at all — the two
	 * reports this preserves are pinned directly by the existence-check fixture.
	 */
	public function aReadOfAnAbsentChildIsNotFollowed(): void
	{
		$form = new RegisteringMethodForm();
		$form->addText('gamma');
		$form->getComponent('nope');

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert\RegisteringMethodForm{
			  gamma: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	/**
	 * The other proof, and the one that is pure attachment: a child the state definitely holds makes
	 * `isset($this->components[$name])` true, so the whole lazy block is skipped whatever factories the
	 * class declares.
	 */
	public function aReadOfAnAttachedChildIsNotFollowed(): void
	{
		$form = new RegisteringMethodForm();
		$form->addText('gamma');
		$form->getComponent('gamma');

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert\RegisteringMethodForm{
			  gamma: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	/**
	 * The arm that is NOT a proof, so the descent happens and degrades honestly: the form declares
	 * `createComponentSub()`, so reading `sub` really does create and attach one. Open, never closed —
	 * the answer a read that may create has to get.
	 */
	public function aReadThatCanRunAFactoryIsFollowed(): void
	{
		$form = new RegisteringMethodFactoryForm();
		$form->addText('gamma');
		$form->getComponent('sub');

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert\RegisteringMethodFactoryForm{
			  gamma: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT);
	}

	/**
	 * A name the call does not spell literally leaves both conditions unanswered, so it degrades the
	 * same way.
	 */
	public function aReadUnderAnUnreadableNameIsFollowed(string $name): void
	{
		$form = new RegisteringMethodForm();
		$form->addText('gamma');
		$form->getComponent($name);

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert\RegisteringMethodForm{
			  gamma: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT);
	}

	/**
	 * The residual, pinned so a later fix is visible rather than silent: the gate reads the callee's
	 * OWN body, so a build method that only delegates registers nothing itself and is not followed —
	 * `alpha` and `beta` stay missing from a closed shape. Inside a descent the same rule applies
	 * again, so the hop is only unreachable at the entry.
	 */
	public function aDelegatingBuilderIsNotFollowed(): void
	{
		$form = new RegisteringMethodForm();
		$form->addText('gamma');
		$form->buildThroughAHelper();

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert\RegisteringMethodForm{
			  gamma: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

}
