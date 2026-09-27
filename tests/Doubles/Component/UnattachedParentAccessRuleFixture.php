<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Component;

use Nette\Application\UI\Control;
use Nette\Forms\Container as FormsContainer;
use Nette\Forms\Controls\TextInput;
use Nette\Forms\Form;

/**
 * The subject of every case here is a component this body CREATED, never `$this` - a container's
 * own chain is normally attached, and a rule that read the container would fire on nearly every
 * factory in a project.
 */
final class UnattachedParentAccessRuleFixture extends Control
{

	/** @var TextInput|null */
	private $stored;

	/**
	 * Every accessor in the table, on components nothing has added yet.
	 */
	private function everyThrowingAccessor(): void
	{
		$input = new TextInput();
		$input->getForm();
		$input->lookup(FormsContainer::class);
		$input->lookupPath();

		$control = new self();
		$control->getPresenter();
		$control->getUniqueId();
	}

	/**
	 * The no-throw spellings answer null instead, so none of them is a defect.
	 */
	private function theNoThrowSpellingsAreSilent(): void
	{
		$input = new TextInput();
		$input->getForm(false);
		$input->lookup(FormsContainer::class, false);
		$input->lookupPath(null, false);

		$control = new self();
		$control->getPresenterIfExists();
	}

	/**
	 * The trap: `$this` inside a factory is the container, whose own chain this walk knows nothing
	 * about, so it stays Maybe and reports nothing.
	 */
	private function theOwnChainIsNotTheSubject(): TextInput
	{
		$this->getPresenter();
		$this->getUniqueId();

		return new TextInput();
	}

	/**
	 * A method resolving to a project override is not the vendor accessor and need not throw at
	 * all, so the table is keyed by declaring class and this drops out of it.
	 */
	private function aProjectOverrideIsNotTheVendorAccessor(): void
	{
		$control = new OverridingComponentFixture();
		$control->getPresenter();
	}

	/**
	 * Attached to A parent, which is all this rule claims about. That the parent has no Form
	 * ancestor - so getForm() throws anyway - is beyond what attachment answers.
	 */
	private function anAttachedComponentIsSilent(): void
	{
		$input = new TextInput();
		$container = new FormsContainer();
		$container->addComponent($input, 'inner');
		$input->getForm();
	}

	private function aRemovedComponentIsDetachedAgain(): void
	{
		$input = new TextInput();
		$container = new FormsContainer();
		$container->addComponent($input, 'inner');
		$container->removeComponent($input);
		$input->getForm();
	}

	/**
	 * Stored somewhere this walk cannot follow, so nothing may be claimed about it afterwards.
	 */
	private function anEscapedReferenceIsSilent(): void
	{
		$input = new TextInput();
		$this->stored = $input;
		$input->getForm();
	}

	private function armsThatDisagreeMeetToMaybe(bool $flag): void
	{
		$input = new TextInput();
		if ($flag) {
			$this->addComponent($input, 'inner');
		}

		$input->getForm();
	}

	private function armsThatAgreeStayDetached(bool $flag): void
	{
		$input = new TextInput();
		if ($flag) {
			$input->setRequired();
		}

		$input->getForm();
	}

	private function aBranchArmIsEnteredAtTheHeaderState(bool $flag): void
	{
		$input = new TextInput();
		if ($flag) {
			$input->getForm();
		}
	}

	/**
	 * The elseif condition runs before the arm and may attach, so the arm claims nothing.
	 */
	private function anElseifConditionThatMayAttachDegrades(bool $flag): void
	{
		$input = new TextInput();
		if ($flag) {
			$input->setRequired();
		} elseif ($this->attachInner($input)) {
			$input->getForm();
		}
	}

	/**
	 * The same shape with a condition that cannot re-parent its own receiver keeps the answer.
	 */
	private function anElseifConditionThatMovesNothingKeepsTheAnswer(bool $flag): void
	{
		$input = new TextInput();
		if ($flag) {
			$input->setRequired();
		} elseif ($input->isRequired()) {
			$input->getForm();
		}
	}

	/**
	 * A loop body is entered from its own exit on the second turn, so the entry is widened and an
	 * access placed above the attach claims nothing.
	 */
	private function aLoopEntryIsWidened(): void
	{
		$input = new TextInput();
		foreach ([1, 2] as $_index) {
			$input->getForm();
			$this->addComponent($input, 'inner');
		}
	}

	/**
	 * The construction is inside the body, so it re-establishes the answer the widening dropped.
	 */
	private function aConstructionInsideALoopStillReports(): void
	{
		foreach ([1, 2] as $_index) {
			$input = new TextInput();
			$input->getForm();
		}
	}

	/**
	 * A closure runs at a time this body does not know, so the enclosing state does not cross into
	 * it.
	 */
	private function aClosureBodyIsNotThisBodysState(): void
	{
		$input = new TextInput();
		$run = static function () use ($input): void {
			$input->getForm();
		};
		$run();
	}

	/**
	 * The same boundary, isolated. A `use` capture mentions the reference twice and is already
	 * refused by the exactly-once rule; an arrow function captures implicitly, so only the boundary
	 * itself can refuse this one.
	 */
	private function anArrowFunctionBodyIsNotThisBodysState(): callable
	{
		$input = new TextInput();

		return static fn (): ?Form => $input->getForm();
	}

	/**
	 * The closure is walked on its own from the initial state, which is where a construction and an
	 * access inside one still meet.
	 */
	private function aConstructionInsideAClosureStillReports(): callable
	{
		return static function (): void {
			$input = new TextInput();
			$input->getForm();
		};
	}

	/**
	 * Two mentions of the receiver in one statement: whatever the other one does, the state at the
	 * top of the statement no longer describes the receiver where the call runs.
	 */
	private function aSecondMentionInTheSameStatementIsSilent(): void
	{
		$input = new TextInput();
		$input->setOption('form', $input->getForm());
	}

	/**
	 * A construction called on directly has no other name, so no statement can have attached it.
	 */
	private function aConstructionCalledOnDirectly(): void
	{
		(new TextInput())->getForm();
	}

	/**
	 * Nette's own `new Form($parent, $name)` attaches from inside the constructor, so a
	 * construction handed arguments claims nothing.
	 */
	private function aConstructorGivenArgumentsIsSilent(): void
	{
		$input = new TextInput('Label');
		$input->getForm();
	}

	/**
	 * The same restriction on the spelling that needs no state at all.
	 */
	private function aConstructionCalledOnDirectlyWithArgumentsIsSilent(): void
	{
		(new TextInput('Label'))->getForm();
	}

	private function attachInner(TextInput $input): bool
	{
		$this->addComponent($input, 'inner');

		return $input->isRequired();
	}

}
