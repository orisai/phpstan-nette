<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Component\Attachment;

use Nette\Application\UI\Control;
use Nette\Forms\Container as FormsContainer;
use Nette\Forms\Controls\TextInput;
use function PHPStan\Testing\assertType;

/**
 * The declared nullable return of a throwing accessor describes the call site that passes false,
 * not the default one: with $throw left true, lookup() raises rather than answering null.
 */
final class ParentAccessorNarrowing
{

	public function theDefaultPathNeverAnswersNull(TextInput $input, Control $control): void
	{
		assertType('Nette\Forms\Form', $input->getForm());
		assertType('string', $input->lookupPath());
		assertType('Nette\Application\UI\Presenter', $control->getPresenter());
	}

	public function theNoThrowPathKeepsItsNull(TextInput $input, Control $control): void
	{
		assertType('Nette\Forms\Form|null', $input->getForm(false));
		assertType('string|null', $input->lookupPath(null, false));
		assertType('Nette\Application\UI\Presenter|null', $control->getPresenterIfExists());
	}

	public function anUnreadableThrowArgumentKeepsTheDeclaredType(TextInput $input, bool $flag): void
	{
		assertType('Nette\Forms\Form|null', $input->getForm($flag));
	}

	/**
	 * lookup() is left to phpstan-nette's own extension, which narrows only the spelling that
	 * writes $throw out - so the default one, which throws just the same, keeps its declared null.
	 */
	public function lookupIsLeftToTheVendorExtension(TextInput $input): void
	{
		assertType('Nette\ComponentModel\IComponent|null', $input->lookup(FormsContainer::class));
		assertType('Nette\ComponentModel\IComponent', $input->lookup(FormsContainer::class, true));
		assertType('Nette\ComponentModel\IComponent|null', $input->lookup(FormsContainer::class, false));
	}

	/**
	 * Nette's own non-throwing overrides answer themselves and were never nullable to begin with.
	 */
	public function anOverrideThatAnswersItselfIsUntouched(FormsContainer $container): void
	{
		assertType('Nette\Forms\Form', $container->getForm());
	}

}
