<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Nette\ComponentModel\Container as ComponentContainer;
use Nette\Forms\Container;
use Nette\Forms\Controls\TextInput;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm;

/**
 * The nag discriminator, both arms.
 *
 * Everything above the divider is a generic adder — one component, named by one of the method's own
 * parameters, reached unconditionally — which is exactly what a single @form-adds tag can describe,
 * so the rule asks for one. Everything below it is a build method by the same test: several
 * components, a name the declaration already fixes, a registration some path skips, or no
 * registration on `$this` at all. Extraction into a package loses information there too, but no tag
 * could have carried it, so asking would be noise.
 */
final class UnannotatedRegistrar extends RawForm
{

	public function addThing(string $name): TextInput
	{
		return $this->addText($name);
	}

	/**
	 * The tag is about what a declaration can express, not about what it is called: a registrar the
	 * add* gate does not even admit is asked for one just the same, because a tag is what would make
	 * it visible.
	 */
	public function attachThing(string $name): TextInput
	{
		return $this->addText($name);
	}

	public function addByOffset(string $name): void
	{
		$this[$name] = new TextInput();
	}

	public function addByComponent(string $name): void
	{
		$this->addComponent(new TextInput(), $name);
	}

	/**
	 * The name is not argument 0, which is the whole reason the tag names a PARAMETER.
	 */
	public function addLabelled(string $label, string $name): TextInput
	{
		return $this->addText($name)->setCaption($label);
	}

	// ---- silent from here on ----

	/**
	 * @form-adds $name
	 */
	public function addDeclared(string $name): TextInput
	{
		return $this->addText($name);
	}

	/**
	 * Two components: a build method, and a tag naming one parameter would describe half of it.
	 */
	public function buildContactSection(string $prefix): void
	{
		$this->addText($prefix . '_email');
		$this->addText($prefix . '_phone');
	}

	/**
	 * Two components, each named by a parameter of its own. Every one of them is annotatable in
	 * isolation and the method is still a build method: one tag would describe half of it, and the
	 * count is the only thing that says so.
	 */
	public function buildRange(string $from, string $to): void
	{
		$this->addText($from);
		$this->addText($to);
	}

	/**
	 * One component, but under a name the declaration composes rather than receives — the walk reads
	 * nothing off the parameter, and neither would a tag.
	 */
	public function addPrefixed(string $prefix): TextInput
	{
		return $this->addText($prefix . '_field');
	}

	public function addFixed(): TextInput
	{
		return $this->addText('fixed');
	}

	/**
	 * A registration some path skips. The tag states an unconditional registration and would lie here.
	 */
	public function addMaybe(string $name, bool $wanted): void
	{
		if ($wanted) {
			$this->addText($name);
		}
	}

	/**
	 * A registration on a container the method was HANDED, which is out of the tag's scope: what
	 * `$this` is has nothing to do with it.
	 */
	public function fillOther(Container $other, string $name): void
	{
		$other->addText($name);
	}

	/**
	 * An add* name that attaches a validation error rather than a component.
	 */
	public function addComplaint(string $message): void
	{
		$this->addError($message);
	}

	/**
	 * The registration is inside a callback; what runs it is not this body.
	 */
	public function addLater(string $name): void
	{
		$this->onRender[] = function () use ($name): void {
			$this->addText($name);
		};
	}

}

/**
 * A component container that is not a FORM container. The tag is only valid on a Nette\Forms\Container
 * subclass, so a registrar declared here could not be annotated and is not asked to be.
 */
final class UnannotatedRegistrarNotAFormContainer extends ComponentContainer
{

	public function addThing(string $name): void
	{
		$this->addComponent(new TextInput(), $name);
	}

}
