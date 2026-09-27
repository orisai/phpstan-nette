<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Type;

use Nette\Forms\Container as NetteContainer;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;
use function PHPStan\Testing\assertType;

/**
 * The gate that keeps an unresolvable container or replicator at its bare component class instead of
 * at an empty shape, asked here on the projector channel. ContainerModel::walk() - the channel every
 * .latte and every non-building consumer goes through - has always asked it (its three
 * ...StaysIComponent fixtures are the pins), and the projector never did, so one runtime lookup
 * answered `IComponent`/the bare class in one file and `FormContainer{}` / `array<int,
 * FormContainer{}>` in another, decided by nothing but whether the enclosing file happens to build a
 * form.
 *
 * An empty shape claims "this component owns nothing". That is a PROOF when the walk saw the whole
 * construction and a fabrication when it did not, which is why the predicate is empty AND open rather
 * than empty alone - closedEmptyContainerKeepsItsShape() below is the half that must survive.
 */
final class UnusableInnerShapeOffset
{

	public function fillRow(FormContainer $c): void
	{
		$c->addText('x');
	}

	private function configure(NetteContainer $c): void
	{
		$c->addText('h');
	}

	/**
	 * A row factory the walk cannot enumerate leaves an inner row shape with no children and an open
	 * marker, so there is no row shape to wrap: the replicator's own class is the answer, and an
	 * offset on it is whatever that class's own ArrayAccess says.
	 */
	public function nonEnumerableReplicatorDegrades(): void
	{
		$form = new ApplicationForm();
		$form->addDynamic('rep', [$this, 'fillRow']);

		assertType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomReplicatorContainer', $form['rep']);
		assertType('Nette\ComponentModel\IComponent', $form['rep'][0]);
	}

	/**
	 * The same question one channel over: a container whose reference escapes into a callee is known
	 * to exist and not known to be empty, so it opens - and an open empty shape is the one a carrier
	 * must not be built over.
	 */
	public function escapedContainerReferenceDegrades(): void
	{
		$form = new ApplicationForm();
		$this->configure($form->addContainer('held'));

		assertType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer', $form['held']);
	}

	/**
	 * The guard. `addContainer('sub')` as a whole statement drops the reference on the floor, so the
	 * shape is empty AND closed - a proof that it owns nothing, and the only carrier
	 * FormShapeUnknownAccessRule has for reporting a name under it (ExistenceCheck::ec28()). Degrading
	 * this one would take the report with it.
	 */
	public function closedEmptyContainerKeepsItsShape(): void
	{
		$form = new ApplicationForm();
		$form->addContainer('sub');

		assertType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{}', $form['sub']);
	}

	/**
	 * A replicator whose rows the walk CAN read is untouched by the gate.
	 */
	public function resolvableReplicatorStillCarriesItsRows(): void
	{
		$form = new ApplicationForm();
		$form->addDynamic('rep', static function (FormContainer $c): void {
			$c->addText('x');
		});

		assertType(
			'array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{x: string}>',
			$form['rep'],
		);
	}

}
