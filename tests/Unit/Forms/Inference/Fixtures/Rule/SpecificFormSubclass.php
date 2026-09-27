<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Nette\Application\UI\Control;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function OriPhpstan\Nette\Forms\Testing\assertComponent;

/**
 * The single largest regression risk of narrowing the registration heuristic, pinned before it was
 * narrowed. Every form below is assembled by a method that is NOT named add*, so what resolves — or
 * degrades — is the walk descending into that method and reading the INNER vendor add* calls. A
 * narrowing that stopped the descent would turn each control into a component the reader is told does
 * not exist, which is a false absence rather than an unresolvable answer.
 *
 * The two shapes an honest answer may take are both here. A build method the walk descends into
 * resolves its controls exactly; one it declines to descend into leaves the shape OPEN, never
 * closed-and-empty. What must never appear is a closed shape missing a control the body registers.
 */
final class SpecificFormSubclass extends Control
{

	protected function createComponentPrivateBuild(): SpecificPrivateBuildForm
	{
		return new SpecificPrivateBuildForm(true);
	}

	protected function createComponentProtectedBuild(): SpecificProtectedBuildForm
	{
		return new SpecificProtectedBuildForm();
	}

	protected function createComponentVendorParent(): SpecificVendorParentForm
	{
		return new SpecificVendorParentForm();
	}

	protected function createComponentNestedBuild(): SpecificNestedBuildForm
	{
		return new SpecificNestedBuildForm();
	}

	/**
	 * The build method lives on the CONTROL and is handed the form as a parameter — the descent
	 * FormShapeAnalyzer performs through resolveContainerParam(), which is one of the sites the add*
	 * name gate answers.
	 */
	protected function createComponentHandedPrivate(): ApplicationForm
	{
		$form = new ApplicationForm();
		$this->fillContactSection($form, true);

		return $form;
	}

	protected function createComponentHandedProtected(): ApplicationForm
	{
		$form = new ApplicationForm();
		$this->fillProfileSection($form);

		return $form;
	}

	/**
	 * The build method is declared on the FORM and called on the form the factory holds. Nothing about
	 * that call is spelled add*.
	 */
	protected function createComponentFormBuilt(): SpecificFactoryBuiltForm
	{
		$form = new SpecificFactoryBuiltForm();
		$form->buildEverything();
		$form->addOnlyOne('gamma');

		return $form;
	}

	/**
	 * A constructor calling a build method is not read by ConstructorFormShapeResolver's statement
	 * predicate — a helper registering controls is not inert — so the shape OPENS. Nothing is claimed
	 * and nothing is denied, which is the honest answer while the descent does not exist. All four
	 * cases below must stay open: a closed shape here would say the form has no controls at all.
	 *
	 * The handed-container pair is the descent that DOES exist, and it resolves exactly. Those two are
	 * the sensitive half of this fixture: they go through resolveContainerParam(), whose registration
	 * gate is one of the sites the add* name answers.
	 *
	 * The last one is the third descent, and the one that used to be missing: a build method declared
	 * on the FORM and called on the form the factory holds. It folded in nothing and did not open
	 * either, so `alpha` and `beta` were missing from a CLOSED shape — a false absence that predated
	 * the adds annotation entirely. It is now descended into, on the strength of its own body
	 * registering, and resolves exactly.
	 */
	public function shapes(): void
	{
		assertComponent($this['privateBuild'], <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\SpecificPrivateBuildForm{
			  ...<IComponent>,
			}
			OUTPUT);
		assertComponent($this['protectedBuild'], <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\SpecificProtectedBuildForm{
			  ...<IComponent>,
			}
			OUTPUT);
		assertComponent($this['vendorParent'], <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\SpecificVendorParentForm{
			  ...<IComponent>,
			}
			OUTPUT);
		assertComponent($this['nestedBuild'], <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\SpecificNestedBuildForm{
			  ...<IComponent>,
			}
			OUTPUT);
		assertComponent($this['handedPrivate'], <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  email: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  phone?: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
		assertComponent($this['handedProtected'], <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  nick: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  public: Nette\Forms\Controls\Checkbox<bool|float|int|string|null, bool>,
			}
			OUTPUT);
		assertComponent($this['formBuilt'], <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\SpecificFactoryBuiltForm{
			  alpha: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  beta: Nette\Forms\Controls\SelectBox<BackedEnum|int|string|null, 'a'|null>,
			  gamma: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	private function fillContactSection(ApplicationForm $form, bool $withPhone): void
	{
		$form->addText('email');

		if ($withPhone) {
			$form->addText('phone');
		}
	}

	protected function fillProfileSection(ApplicationForm $form): void
	{
		$form->addText('nick');
		$form->addCheckbox('public');
	}

}
