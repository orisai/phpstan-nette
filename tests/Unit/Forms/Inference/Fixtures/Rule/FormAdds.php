<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use function OriPhpstan\Nette\Forms\Testing\assertComponent;

final class FormAdds
{

	public function declaredReturnType(): void
	{
		$form = new FormAddsForm();
		$form->addThing('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\FormAddsForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	public function nameParameterIsNotArgumentZero(): void
	{
		$form = new FormAddsForm();
		$form->addLabelled('Label', 'city');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\FormAddsForm{
			  city: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	public function classFromTagWhenNothingIsReturned(): void
	{
		$form = new FormAddsForm();
		$form->addAttached('note');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\FormAddsForm{
			  note: Nette\Forms\Controls\TextArea<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	/**
	 * The class operand and the declared return type are two sources of ONE thing: naming the class
	 * the method already returns may not resolve to anything the omission does not.
	 */
	public function explicitClassMatchesDeclaredReturn(): void
	{
		$form = new FormAddsForm();
		$form->addRedundantlyClassed('a');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\FormAddsForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	/**
	 * The boundary, pinned where it now is: a declaration under a name the component-affecting pass
	 * does not mark still resolves no NAME — that pass gates on the name alone, deliberately, since
	 * its node ids are the walk's cache key — but it no longer proves the component ABSENT. The shape
	 * OPENS instead, on the strength of the declaration, so `$form['ghost']` degrades rather than
	 * reporting that a component the author declared does not exist.
	 *
	 * Naming the helper add* is still what makes it resolve precisely, and is still what the tag is
	 * written for.
	 */
	public function aDeclarationUnderAnUntaggedNameOpensRatherThanClosing(): void
	{
		$form = new FormAddsForm();
		$form->attachThing('ghost');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\FormAddsForm{
			  ...<IComponent>,
			}
			OUTPUT);
	}

	public function repeatedTagAddsBoth(): void
	{
		$form = new FormAddsForm();
		$form->addPair('one', 'two');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\FormAddsForm{
			  one: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  two: Nette\Forms\Controls\SelectBox<BackedEnum|int|string|null, int|string|null>,
			}
			OUTPUT);
	}

	/**
	 * Presence no longer follows the declared return type alone. A void return says nothing about what
	 * was registered, and the body says one component under argument 0 — so the component is present,
	 * with no value type, exactly as an add* helper declaring no return type at all resolves. Before
	 * this, writing `: void` — the MORE informative declaration — dropped the name from a shape that
	 * then closed and proved the component missing.
	 */
	public function anUnannotatedVoidRegistrarIsPresent(): void
	{
		$form = new FormAddsForm();
		$form->addByOffset('slot');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\FormAddsForm{
			  slot: *mixed*<*any*, *unknown*>,
			  ...<IComponent>,
			}
			OUTPUT);
	}

	/**
	 * The same body registering under a name argument 0 does not carry. Argument 0 is the label, and
	 * registering the label would prove the real component absent, so nothing is recorded and the
	 * shape opens. The tag is what resolves it; the body only ever disproves.
	 */
	public function aVoidRegistrarNamingSomethingElseDegrades(): void
	{
		$form = new FormAddsForm();
		$form->addLabelledByOffset('Label', 'city');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\FormAddsForm{
			  ...<IComponent>,
			}
			OUTPUT);
	}

	/**
	 * The other side of the same reading, and the one that keeps a name blocklist from growing: an
	 * add* name on a container whose body registers nothing records nothing, and the shape stays
	 * CLOSED. Nette's own addError() and addGroup() are inert here for that reason and not because
	 * they are listed anywhere.
	 */
	public function anAddNameRegisteringNothingStaysClosed(): void
	{
		$form = new FormAddsForm();
		$form->addText('real');
		$form->addComplaint('boom');
		$form->addError('boom');
		$form->addGroup('grp');
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\FormAddsForm{
			  real: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	public function nonConstantNameDegrades(string $dynamic): void
	{
		$form = new FormAddsForm();
		$form->addLabelled('Label', $dynamic);
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\FormAddsForm{
			  ...<IComponent>,
			}
			OUTPUT);
	}

}
