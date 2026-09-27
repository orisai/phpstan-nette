<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;
use function PHPStan\dumpType;

/**
 * A replicator offset whose VALUE is not statically known, but whose TYPE PHPStan proves is a
 * decimal-integer string - the same on-demand row an integer offset names. `(string) $i` reaches that
 * type with no annotation at all.
 *
 * The negative cases pin that the row arm is not over-eager: a plain string, a numeric-string (which
 * covers '1.5' and so may be neither a row nor a name), and a string PHPStan proves can NOT collapse
 * to an int key all stay at the wrapped class's own IComponent answer, because none of them yields a
 * NAME to look up in the replicator's own children.
 */
final class ReplicatorDecimalStringOffsetResolves
{

	private function form(): ApplicationForm
	{
		$form = new ApplicationForm();
		$rep = $form->addDynamic('rep', static function (FormContainer $c): void {
			$c->addText('a');
		});
		$rep->addSubmit('addNode', 'Add');

		return $form;
	}

	public function castOfAnInteger(int $i): void
	{
		$form = $this->form();
		$key = (string) $i;
		dumpType($form['rep'][$key]); // => Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{a: string}
		dumpType($form['rep'][$key]['a']); // => Nette\Forms\Controls\TextInput
	}

	/** @param numeric-string $k */
	public function numericStringStaysUnresolved(string $k): void
	{
		$form = $this->form();
		dumpType($form['rep'][$k]); // => Nette\ComponentModel\IComponent
	}

	/** @param non-decimal-int-string $k */
	public function provenNonRowStaysUnresolved(string $k): void
	{
		$form = $this->form();
		dumpType($form['rep'][$k]); // => Nette\ComponentModel\IComponent
	}

	public function plainStringStaysUnresolved(string $k): void
	{
		$form = $this->form();
		dumpType($form['rep'][$k]); // => Nette\ComponentModel\IComponent
	}

	public function constantOwnChildStillWins(): void
	{
		$form = $this->form();
		dumpType($form['rep']['addNode']); // => Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton
	}

}
