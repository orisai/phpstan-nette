<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

/**
 * A callback STORED on an event property is the MAYBE arm of the invocation-timing trinary
 * MCallbackInvocationTiming spells out, reached by a different road: a store is not a call, so there
 * is no parameter for the trinary to read its answer off, and the walk recognises the assignment
 * grammar instead.
 *
 * MAYBE and not NO, for two independent reasons. Nette fires onSuccess during form PROCESSING, which
 * precedes rendering, so the add is deferred relative to the FACTORY and not relative to every
 * reader. And goDeferred() is public: its call sites are unbounded, including one inside an
 * onSuccess handler or under an isSuccess() guard, by which time the control is attached. Absence is
 * therefore not provable, and absence is what turns into a report — so the shape OPENS, the deferred
 * name degrades to mixed and NOTHING is reported. Only a callable whose body is read and seen to
 * register reaches this arm; the handler that merely processes values, which is what nearly all of
 * them do, leaves the shape closed and its absence claims intact.
 *
 * Visibility is what a proof of absence would need and does not have here: private, or protected in
 * an effectively final class, would make the reader set enumerable, where public cannot — a
 * NECESSARY condition, not a sufficient one, since a private method is still reachable from a
 * closure defined in the same class.
 */
final class MCallbackDeferredStoredStaysUnknown extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('immediate');

		$deferred = static function (ApplicationForm $f): void {
			$f->addText('deferred');
		};
		$form->onSuccess[] = $deferred;

		return $form;
	}

	public function goImmediate(): void
	{
		dumpType($this['form']['immediate']); // => Nette\Forms\Controls\TextInput
	}

	public function goDeferred(): void
	{
		dumpType($this['form']['deferred']); // => mixed
	}

}
