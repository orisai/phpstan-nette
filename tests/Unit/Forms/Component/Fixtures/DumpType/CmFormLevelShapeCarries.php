<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\Support\FmFormLevelShapeControl;
use function PHPStan\dumpType;

/**
 * A form-level access carries the form's shape, which is what every template reads: this file builds
 * no form of its own, so ContainerModel answers each offset below exactly as it answers
 * {var $form = $control['form']}.
 *
 * Three capabilities ride on that one carrier and nothing else pins them together:
 *
 * - PRESENCE. An unconditionally added child is Yes, so `?? null` keeps no null of its own. There is
 *   no |null redundancy behind this - a wrong Yes has no backstop - and the answer comes from
 *   ComponentPath::hasDefiniteChild()'s precedence, never from a second opinion.
 * - VALUES. getValues() projects the shape into the crate rather than answering a flat ArrayHash.
 * - ABSENCE. A name the CLOSED shape proves absent is the projector's ErrorType, reported once by
 *   FormShapeUnknownAccessRule off this same receiver; UnknownNameOnFormLevelShape.php runs the real
 *   analysis and counts. An OPEN form proves nothing and every unknown name there degrades instead -
 *   the two rows at the end are that boundary, and they are the whole reason the carrier may not
 *   simply answer ErrorType whenever the walk runs out of channels.
 */
final class CmFormLevelShapeCarries
{

	public function render(FmFormLevelShapeControl $control): void
	{
		dumpType($control['form']); // => Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{name: string, sub: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{inner: string}}

		$form = $control['form'];
		dumpType($form['name'] ?? null); // => Nette\Forms\Controls\TextInput
		dumpType($form->getValues()); // => Nette\Utils\ArrayHash{name: string, sub: Nette\Utils\ArrayHash{inner: string}}
		dumpType($form['nope']); // => *ERROR*
		dumpType($form['sub-nope']); // => *ERROR*

		$open = $control['openForm'];
		dumpType($open); // => Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{base: string, …+unknown(dynamic_name)}
		dumpType($open['nope']); // => Nette\ComponentModel\IComponent
	}

}
