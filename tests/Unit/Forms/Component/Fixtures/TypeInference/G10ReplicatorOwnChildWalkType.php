<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\TypeInference;

use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\Support\FmReplicatorOwnChildControl;
use function PHPStan\Testing\assertType;

/**
 * The ContainerModel walk channel — the one a .latte uses. This file builds no form of its own, so
 * FormFileIndex::hasAnyTrackedForm() is false for it and FormAccessExpressionTypeResolver (which
 * G9SeparatorPathOwnChildType exercises instead) never answers; every offset below is resolved by
 * ContainerModel::walk() through ComponentModelAccessDynamicReturnTypeExtension, exactly as a
 * template's $control['form'][…] is.
 */
final class G10ReplicatorOwnChildWalkType
{

	public function render(FmReplicatorOwnChildControl $control): void
	{
		// A control added straight onto the replicator (addDynamic()'s return value) reached through a
		// mid-chain replicator segment: its OWN type, not the row's and not a bare IComponent.
		assertType('Nette\Forms\Controls\SubmitButton', $control['form']['outer']['rep']['addNode']);

		// The separator-joined spelling of the same runtime lookup - Nette's Container::getComponent()
		// splits on IComponent::NameSeparator, so both must answer identically.
		assertType('Nette\Forms\Controls\SubmitButton', $control['form']['outer']['rep-addNode']);

		// No regression on the ROW path: an integer offset is still a row of the replicator, never an
		// own child.
		assertType('Nette\Forms\Container{x: string}', $control['form']['outer']['rep'][0]);

		// A name that is neither an own child nor a row degrades to the wrapped class's own ArrayAccess
		// answer - never ErrorType, never a confidently-wrong row.
		assertType('Nette\ComponentModel\IComponent', $control['form']['outer']['rep-nope']);
		assertType('Nette\ComponentModel\IComponent', $control['form']['outer']['rep']['nope']);

		// A DECIMAL segment past the replicator is a dynamically created row (Nette casts an int
		// offset to a string, and NameRegexp accepts digits), so every spelling of row 0's 'x' is the
		// same lookup on this channel too.
		assertType('Nette\Forms\Container{x: string}', $control['form']['outer']['rep']['0']);
		assertType('Nette\Forms\Controls\TextInput', $control['form']['outer']['rep'][0]['x']);
		assertType('Nette\Forms\Controls\TextInput', $control['form']['outer']['rep']['0-x']);
		assertType('Nette\Forms\Controls\TextInput', $control['form']['outer']['rep-0-x']);
		assertType('Nette\Forms\Controls\TextInput', $control['form']['outer-rep-0-x']);

		// An empty segment names no component Container::addComponent() could ever have registered,
		// so the whole path is unresolvable - and in particular must NOT reach classHop(), where
		// ucfirst('') finds Container::createComponent() itself.
		assertType('Nette\ComponentModel\IComponent', $control['form']['outer-']);
		assertType('Nette\ComponentModel\IComponent', $control['form']['outer']['rep--x']);
	}

}
