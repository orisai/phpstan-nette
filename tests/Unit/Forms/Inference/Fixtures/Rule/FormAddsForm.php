<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Nette\Forms\Controls\TextInput;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm;

/**
 * Generic adders whose component name arrives as a parameter — the one case the walk cannot resolve
 * from the declaration, and the only case the adds annotation exists for.
 */
final class FormAddsForm extends RawForm
{

	/**
	 * @form-adds $name
	 */
	public function addThing(string $name): TextInput
	{
		return $this->addText($name);
	}

	/**
	 * The name is NOT argument 0. Without the tag the walk reads the label as the component name.
	 *
	 * @form-adds $name
	 */
	public function addLabelled(string $label, string $name): TextInput
	{
		return $this->addText($name)->setCaption($label);
	}

	/**
	 * The method does not return the control, so the class can only come from the tag.
	 *
	 * @form-adds $name Nette\Forms\Controls\TextArea
	 */
	public function addAttached(string $name): void
	{
		$this->addText($name);
	}

	/**
	 * The tag names exactly what the return type already names. Spelling the class out is a
	 * redundancy, not a second meaning, so this must resolve identically to addThing().
	 *
	 * @form-adds $name Nette\Forms\Controls\TextInput
	 */
	public function addRedundantlyClassed(string $name): TextInput
	{
		return $this->addText($name);
	}

	/**
	 * A docblock that merely NAMES the tag does not carry it. This method mentions @form-adds in the
	 * middle of a sentence, and the next line opens with a quoted one:
	 * `@form-adds` is read only where it opens a line as a tag, not where prose discusses it.
	 *
	 * Without that anchoring the extension's own source — which documents the tag at length — reports
	 * itself, which is exactly what happened before this fixture existed.
	 */
	public function addProseMentionIsNotATag(string $name): TextInput
	{
		return $this->addText($name);
	}

	/**
	 * Presence is still decided by the add* NAME, which this method does not have, so a perfectly
	 * valid tag registers nothing here. Slice 1 supplies the class axis only.
	 *
	 * @form-adds $name Nette\Forms\Controls\TextInput
	 */
	public function attachThing(string $name): void
	{
		$this->addText($name);
	}

	/**
	 * One call, two components, under distinct name parameters.
	 *
	 * @form-adds $first Nette\Forms\Controls\TextInput
	 * @form-adds $second Nette\Forms\Controls\SelectBox
	 */
	public function addPair(string $first, string $second): void
	{
		$this->addText($first);
		$this->addText($second);
	}

	/**
	 * An UNANNOTATED registrar that hands nothing back. A void return says nothing about what was
	 * registered, so the body answers instead: one component, named by argument 0.
	 */
	public function addByOffset(string $name): void
	{
		$this[$name] = new TextInput();
	}

	/**
	 * The same, under a name argument 0 does not carry. Reading argument 0 would register the label
	 * and prove the real component absent, so nothing is recorded and the shape opens.
	 */
	public function addLabelledByOffset(string $label, string $name): void
	{
		$this[$name] = new TextInput($label);
	}

	/**
	 * An add* name on a container that registers no component at all. Nothing is recorded and the
	 * shape stays CLOSED — the body is what says so, not a list of vendor names.
	 */
	public function addComplaint(string $message): void
	{
		$this->addError($message);
	}

}
