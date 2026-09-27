<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\TypeInference;

use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\Support\FmReplicatorRowSubmitControl;
use function PHPStan\Testing\assertType;

/**
 * A submit button added inside a replicator's ITEM FACTORY - a child of every row, held by the row
 * shape's componentTypes and by no shaped channel - reached on the ContainerModel walk channel, the
 * one a .latte uses. This file builds no form of its own, so FormFileIndex::hasAnyTrackedForm() is
 * false for it and FormAccessExpressionTypeResolver never answers.
 *
 * The path lands in FormReplicatorType::pathType()'s row descent, which asks
 * FormShapeProjector::offsetPath() for the remainder - the leaf that could see only the three
 * shaped channels until childType() existed, and answered the row shape's mixed here.
 */
final class G12ReplicatorRowSubmitWalkType
{

	public function render(FmReplicatorRowSubmitControl $control): void
	{
		// Every spelling of row 0's remove button is the same runtime lookup, so all three answer
		// with the button's own class rather than the row shape's open mixed.
		assertType('Nette\Forms\Controls\SubmitButton', $control['form']['outer']['rep-0-removeNode']);
		assertType('Nette\Forms\Controls\SubmitButton', $control['form']['outer']['rep']['0-removeNode']);
		assertType('Nette\Forms\Controls\SubmitButton', $control['form']['outer-rep-0-removeNode']);

		// The replicator's OWN children are a different name set from a row's, and neither leaks into
		// the other: 'addNode' is not in a row and 'removeNode' is not on the replicator.
		assertType('Nette\Forms\Controls\SubmitButton', $control['form']['outer']['rep-addNode']);
		assertType('Nette\ComponentModel\IComponent', $control['form']['outer']['rep-removeNode']);
		assertType('Nette\ComponentModel\IComponent', $control['form']['outer']['rep-0-addNode']);

		// No regression on the row's shaped children, or on a name held in no channel at all.
		assertType('Nette\Forms\Controls\TextInput', $control['form']['outer']['rep-0-x']);
		assertType('Nette\ComponentModel\IComponent', $control['form']['outer']['rep-0-nope']);
	}

}
