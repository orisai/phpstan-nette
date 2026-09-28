<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms;

use Nette\Application\UI\Control;
use Nette\ComponentModel\IComponent;
use Nette\Forms\Container;
use Nette\Forms\Controls\Checkbox;
use Nette\Forms\Controls\CheckboxList;
use Nette\Forms\Controls\CsrfProtection;
use Nette\Forms\Controls\HiddenField;
use Nette\Forms\Controls\ImageButton;
use Nette\Forms\Controls\RadioList;
use Nette\Forms\Controls\SubmitButton;
use Nette\Forms\Controls\TextInput;
use OriPhpstan\Nette\LatteForms\ComponentIdentity;
use OriPhpstan\Nette\LatteForms\ControlReference;
use OriPhpstan\Nette\LatteForms\MacroSuitability;
use PHPStan\Testing\PHPStanTestCase;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\LabelledButton;

// The vendor-semantics table on its own, as a pure function of (macro kind, component identity).
// Every row is read off vendor's compiled output rather than off any intuition about what a macro
// "means" - see MacroSuitability's own header for the four compiled forms it encodes.
final class MacroSuitabilityTest extends PHPStanTestCase
{

	private const CONTROL_MACROS = [
		ControlReference::KIND_INPUT,
		ControlReference::KIND_INPUT_ERROR,
		ControlReference::KIND_LABEL,
		ControlReference::KIND_NAME_ATTR,
	];

	// {input}/{inputError}/{label}/n:name each compile into a method Nette\Forms\Container does not
	// declare, so none of them can address a container - and the container's own macro can.
	public function testNoControlMacroCanAddressAContainerAndTheContainerMacroCan(): void
	{
		$container = new ComponentIdentity(ComponentIdentity::KIND_CONTAINER, [Container::class]);

		foreach (self::CONTROL_MACROS as $kind) {
			self::assertSame(
				MacroSuitability::MISMATCH_CONTAINER_AS_CONTROL,
				$this->suitability()->mismatch($kind, $container),
				$kind . ' compiles into a method no container declares',
			);
		}

		self::assertNull($this->suitability()->mismatch(ControlReference::KIND_CONTAINER, $container));
	}

	// A container with no recorded class is still a container: the kind comes from the channel the
	// shape recorded it in, and no class evidence is needed to know a control macro cannot reach it.
	public function testAClasslessContainerIsStillOutOfReachOfAControlMacro(): void
	{
		self::assertSame(
			MacroSuitability::MISMATCH_CONTAINER_AS_CONTROL,
			$this->suitability()->mismatch(
				ControlReference::KIND_INPUT,
				new ComponentIdentity(ComponentIdentity::KIND_CONTAINER, null),
			),
		);
	}

	// {formContainer} offsets what it is given, and Nette\Forms\Controls\BaseControl is no
	// ArrayAccess - while every macro that renders a control is right at home on one.
	public function testAControlCanBeRenderedButNotScopedInto(): void
	{
		$control = new ComponentIdentity(ComponentIdentity::KIND_CONTROL, [TextInput::class]);

		self::assertSame(
			MacroSuitability::MISMATCH_CONTROL_AS_CONTAINER,
			$this->suitability()->mismatch(ControlReference::KIND_CONTAINER, $control),
		);

		foreach (self::CONTROL_MACROS as $kind) {
			self::assertNull($this->suitability()->mismatch($kind, $control), $kind . ' is what a control is for');
		}
	}

	// The recorded class is equal to or WIDER than the runtime one, so only a definite NO transfers.
	// Three ways of not having one: a class that IS a container, an INTERFACE any container could
	// still implement (PHPStan answers maybe, and maybe is not evidence), and no class at all.
	public function testOnlyAClassDefinitelyUnrelatedToAContainerIsScopedIntoWrongly(): void
	{
		$isOne = new ComponentIdentity(ComponentIdentity::KIND_CONTROL, [Control::class]);
		$maybe = new ComponentIdentity(ComponentIdentity::KIND_CONTROL, [IComponent::class]);
		$unknown = new ComponentIdentity(ComponentIdentity::KIND_CONTROL, null);
		$mixed = new ComponentIdentity(ComponentIdentity::KIND_CONTROL, [TextInput::class, IComponent::class]);

		self::assertNull($this->suitability()->mismatch(ControlReference::KIND_CONTAINER, $isOne));
		self::assertNull($this->suitability()->mismatch(ControlReference::KIND_CONTAINER, $maybe));
		self::assertNull($this->suitability()->mismatch(ControlReference::KIND_CONTAINER, $unknown));
		self::assertNull($this->suitability()->mismatch(ControlReference::KIND_CONTAINER, $mixed));
	}

	// Button::getLabel() and HiddenField::getLabel() are vendor's own `return null`, so {label} on
	// one of them renders nothing at all. Both hierarchies inherit it, and the controls that DO
	// override it - Checkbox and the two list controls, whose labels are the point of them - do not.
	public function testTheLabelBypassingControlsAreExactlyTheTwoVendorHierarchies(): void
	{
		foreach ([SubmitButton::class, ImageButton::class, HiddenField::class, CsrfProtection::class] as $class) {
			self::assertSame(
				MacroSuitability::MISMATCH_NO_LABEL,
				$this->suitability()->mismatch(
					ControlReference::KIND_LABEL,
					new ComponentIdentity(ComponentIdentity::KIND_CONTROL, [$class]),
				),
				$class . ' inherits a getLabel() whose whole body is `return null`',
			);
		}

		foreach ([TextInput::class, Checkbox::class, CheckboxList::class, RadioList::class] as $class) {
			self::assertNull(
				$this->suitability()->mismatch(
					ControlReference::KIND_LABEL,
					new ComponentIdentity(ComponentIdentity::KIND_CONTROL, [$class]),
				),
				$class . ' renders a label of its own',
			);
		}
	}

	// THE SUBCLASS PIN. Ancestry is not the question - the DECLARING class of getLabel() is. A
	// project button that overrides it renders a label again, and the repo's own
	// App\Base\Form\CustomSubmitButton (which does not override) is why the other direction matters.
	public function testAButtonThatOverridesGetLabelIsNeverReported(): void
	{
		self::assertNull(
			$this->suitability()->mismatch(
				ControlReference::KIND_LABEL,
				new ComponentIdentity(ComponentIdentity::KIND_CONTROL, [LabelledButton::class]),
			),
		);

		self::assertSame(
			MacroSuitability::MISMATCH_NO_LABEL,
			$this->suitability()->mismatch(
				ControlReference::KIND_LABEL,
				new ComponentIdentity(ComponentIdentity::KIND_CONTROL, [SubmitButton::class]),
			),
			'its own parent, which does not override it, still is',
		);
	}

	// A button is a control like any other for every macro that renders one: {input send} is how a
	// button is MEANT to be rendered, and only the label question has a different answer.
	public function testALabellessControlIsOnlyWrongForTheLabelMacro(): void
	{
		$button = new ComponentIdentity(ComponentIdentity::KIND_CONTROL, [SubmitButton::class]);

		self::assertNull($this->suitability()->mismatch(ControlReference::KIND_INPUT, $button));
		self::assertNull($this->suitability()->mismatch(ControlReference::KIND_INPUT_ERROR, $button));
		self::assertNull($this->suitability()->mismatch(ControlReference::KIND_NAME_ATTR, $button));
	}

	// Two classes joined from two branches: the verdict has to hold for BOTH, or the macro is right
	// on one of the paths this component can take.
	public function testAJoinedClassSetIsReportedOnlyWhenEveryMemberBypassesItsLabel(): void
	{
		self::assertSame(
			MacroSuitability::MISMATCH_NO_LABEL,
			$this->suitability()->mismatch(
				ControlReference::KIND_LABEL,
				new ComponentIdentity(ComponentIdentity::KIND_CONTROL, [SubmitButton::class, HiddenField::class]),
			),
		);

		self::assertNull(
			$this->suitability()->mismatch(
				ControlReference::KIND_LABEL,
				new ComponentIdentity(ComponentIdentity::KIND_CONTROL, [SubmitButton::class, TextInput::class]),
			),
		);
	}

	// A class name the reflector cannot place, and an empty class set, are both the analyser
	// declining to say - never evidence that a label is missing.
	public function testAnUnplaceableOrEmptyClassSetProvesNothing(): void
	{
		foreach ([['Ghost\\NoSuchControl'], [], null] as $classes) {
			self::assertNull(
				$this->suitability()->mismatch(
					ControlReference::KIND_LABEL,
					new ComponentIdentity(ComponentIdentity::KIND_CONTROL, $classes),
				),
			);
		}
	}

	private function suitability(): MacroSuitability
	{
		return new MacroSuitability(self::createReflectionProvider());
	}

}
