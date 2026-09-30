<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert;

use Nette\Forms\Controls\BaseControl;
use Nette\Forms\Form;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function OriPhpstan\Nette\Forms\Testing\assertComponent;

/**
 * A child pulled out of the tracked form into a local — `$probe = $form['a']`, or the
 * `$form->getComponent('a')` spelling of the same runtime lookup.
 *
 * The read used to open the form's shape on sight, which is not a widened member type: an open shape
 * reports NO absence at all, so every form where someone pulled a control into a local and called a
 * setter on it lost `orisai.nette.forms.noSuchComponent` for the whole form. It also split two spellings of one
 * component set — `$form->addText('a')->setDisabled()` has always closed — on syntax alone.
 *
 * What decides it now is the handle itself, on two axes that must BOTH hold. The child has to be one
 * the walk's own state resolves to a SINGLE CLASS, which is what drops the containers and replicators
 * out — a child whose own shape the form carries resolves to none, so a handle on it never reaches the
 * use question. And every occurrence of the local has to be one of the three the walk recognises as
 * registering nothing — a re-read of the tracked form, an `instanceof` probe, or a bare-statement call
 * chain whose every hop is a non-registering, non-creating name. Anything else is not inert, it is
 * unanswered, and the shape opens exactly as it always did.
 *
 * A third axis is gone: the resolved class also had to be no `Nette\Forms\Container`, a hardcoded pair
 * of names standing in for the use predicate's one hole — `getComponent()` is not an add* name, so the
 * registering-name authority passed it, while the container method of that name creates and attaches
 * what it does not find. The hole is closed inside the use predicate, which is where it holds for
 * every caller; `ComponentHandleUsesTest` asserts it there.
 */
final class PulledChildHandle
{

	/**
	 * The flip: a leaf control pulled out and only modified. Identical in effect to the chained
	 * spelling, so identical in outcome — closed, and `$form['nope']` reportable again.
	 */
	public function aLeafPulledOutAndModifiedStaysClosed(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$probe = $form['a'];
		$probe->setDefaultValue('x');

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	/**
	 * The getComponent() spelling of the same lookup, with the `instanceof` probe the idiom carries
	 * (offsetGet delegates to getComponent, and the probe hands the object nowhere).
	 */
	public function theGetComponentSpellingIsTheSame(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$probe = $form->getComponent('a');
		assert($probe instanceof BaseControl);
		$probe->setDefaultValue('x');

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	/**
	 * `addRule` is an add* name that attaches a validation rule rather than a component, and the walk's
	 * own registering-name authority already says so — the same authority that decides what an add on
	 * the tracked form itself means.
	 */
	public function aNonRegisteringAddOnTheHandleStaysClosed(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$probe = $form['a'];
		$probe->addRule(Form::FILLED);

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	/**
	 * The safe floor for a child whose OWN shape the form carries. A container resolves to no single
	 * child class — the state holds it on the containers channel instead — so the read opens the shape
	 * exactly as it always has, including for a call that only reads, since the container's own later
	 * mutations are the unread half.
	 */
	public function aContainerPulledOutOpens(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form->addContainer('sub');
		$holder = $form['sub'];
		$holder->setDefaults([]);

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  sub: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{},
			  ...<IComponent>,
			}
			OUTPUT);
	}

	/**
	 * The safe floor by USE, first half: the handle is handed to a callee. The walk does not read what
	 * the callee does with it, so the form may gain a field through it.
	 */
	public function aHandlePassedOnOpens(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$probe = $form['a'];
		$this->tweak($probe);

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT);
	}

	/**
	 * The safe floor by USE, second half, and the reason every hop of a chain is checked rather than
	 * only the first: the receiver of a later hop is whatever the earlier one returned, and
	 * `getForm()` returns the tracked form itself. `late` is registered under a receiver the walk
	 * cannot attribute, so it is neither listed nor claimed absent.
	 */
	public function aRegisteringHopThroughTheHandleOpens(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$probe = $form['a'];
		$probe->getForm()->addText('late');

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT);
	}

	/**
	 * The safe floor for a name the walk cannot read: a non-literal offset resolves to no child, so
	 * there is no class to decide the type axis on.
	 */
	public function aNonLiteralOffsetOpens(string $name): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$probe = $form[$name];
		$probe->setDisabled();

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT);
	}

	/**
	 * The safe floor for a read that is not a statement of its own: the handle stays reachable from an
	 * expression the use scan does not account for, so the assignment is not proven to be the whole of
	 * what happens to it.
	 */
	public function anAssignmentNestedInAChainOpens(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		($probe = $form['a'])->setDisabled();

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT);
	}

	/**
	 * The known limitation, pinned so a later fix is visible rather than silent: a chain whose RESULT
	 * is captured is not a recognised use, because the captured value may be the form itself
	 * (`$f = $probe->getForm();`) and the walk does not follow where it goes next. Reading a control's
	 * value in a builder is rare enough that the coarser rule is the cheaper one.
	 */
	public function aCapturedChainResultOpens(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$probe = $form['a'];
		$value = $probe->getValue();
		$form->setAction((string) $value);

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT);
	}

	private function tweak(BaseControl $control): void
	{
		$control->setDisabled();
	}

}
