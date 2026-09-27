<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\TypeInference;

use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\Support\FmClosedAndOpenContainerControl;
use function PHPStan\Testing\assertType;

/**
 * The ContainerModel walk channel - the one a .latte uses. This file builds no form of its own, so
 * FormFileIndex::hasAnyTrackedForm() is false for it and FormAccessExpressionTypeResolver never
 * answers; every offset below is resolved by ContainerModel through
 * ComponentModelAccessDynamicReturnTypeExtension, exactly as a template's $control['form'][…] is.
 *
 * Under test: a name a CLOSED shape proves absent is the same ErrorType here as on the projector
 * channel (G11ComponentPathEquivalenceType and Offset::g1_18()/g1_22() pin it there). The absence is
 * FormShapeUnknownAccessRule's single report, and the ErrorType is what stops the same access being
 * reported a second time as an undefined method, property or offset on an IComponent -
 * UnknownNameOnClosedShapeThroughWalk.php runs the real analysis and counts.
 */
final class G13WalkProvenAbsentLeafType
{

	public function render(FmClosedAndOpenContainerControl $control): void
	{
		// The receiver is what turns absence into a PROOF: the walk wrapped this container in a
		// FormShapeType, so the rule reads the same shape off the same node and reports.
		assertType(
			'Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{a: string}',
			$control['form']['closed'],
		);
		assertType('*ERROR*', $control['form']['closed']['nope']);

		// An OPEN shape rules nothing out. It degrades, exactly as it always has - this is the
		// unresolvable case, and it must never become an ErrorType.
		assertType('Nette\ComponentModel\IComponent', $control['form']['open']['nope']);

		// An unknown name on the FORM degrades as well. The single-segment access carries the form's
		// own shape now, so there is a shape to read - but this one is OPEN, because
		// Nette\Application\UI\Form's own constructor build cannot be read, and an open shape proves
		// nothing. Only a closed shape may mint an ErrorType, which is what keeps unresolvable and
		// proven-absent apart. The form-level shape itself, and the proven-absent answer a CLOSED
		// form gives for the same spelling (including the separator-joined one), are pinned by
		// Fixtures/DumpType/CmFormLevelShapeCarries.php, which runs against the real configuration.
		assertType('Nette\ComponentModel\IComponent', $control['form']['nope']);

		// A name the shape DOES hold is untouched by any of it.
		assertType('Nette\Forms\Controls\TextInput', $control['form']['closed']['a']);
	}

}
