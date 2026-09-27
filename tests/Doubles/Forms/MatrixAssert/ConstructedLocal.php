<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert;

use Nette\Application\UI\Form;
use Nette\Forms\Form as PlainForm;
use function OriPhpstan\Nette\Forms\Testing\assertComponent;

class ConstructedLocalForm extends Form
{

	public function __construct()
	{
		parent::__construct();

		$this->addText('alpha');
		$this->addCheckbox('beta');
	}

}

class ConstructedLocalPlainForm extends Form
{

}

class ConstructedLocalHelperBuiltForm extends Form
{

	public function __construct()
	{
		parent::__construct();

		$this->build();
	}

	private function build(): void
	{
		$this->addText('delta');
	}

}

final class ConstructedLocalFactory
{

	public function create(): ConstructedLocalForm
	{
		return new ConstructedLocalForm();
	}

}

/**
 * A form assembled by its OWN constructor, held the two ways a caller can hold it. What every case
 * here asserts is that the answer is a property of the class rather than of the caller's spelling: a
 * factory's `return new X()` has always been shaped by ConstructedFormShapeResolver, and the plain
 * local beside it used to start from an empty state that never consulted that resolver at all — so
 * the same class resolved through one spelling and had every control its constructor added PROVEN
 * ABSENT through the other.
 *
 * The two honest answers are both pinned. A constructor the resolver reads gives the controls
 * exactly; one it declines to read leaves the shape OPEN. What must not appear is the third answer,
 * an empty CLOSED shape, which says the form has no controls at all.
 */
final class ConstructedLocalReader
{

	private ConstructedLocalFactory $factory;

	public function __construct(ConstructedLocalFactory $factory)
	{
		$this->factory = $factory;
	}

	/**
	 * The two bare Nette bases, pinned because a hardcoded pair of class names is what keeps them
	 * closed and nothing else in this repo asserts either of them.
	 *
	 * Both constructors do their only component work under a guard a zero-argument construction can
	 * never pass — `Application\UI\Form` runs `$parent->addComponent($this, $name)` inside
	 * `if ($parent !== null)`, `Forms\Form` builds its tracker inside `if ($name !== null)` — so both
	 * provably add nothing and the shape must close over whatever the caller adds afterwards. Read
	 * without that knowledge the same bodies open, and `$form['nope']` stops being reportable on the
	 * commonest form-building spelling there is.
	 */
	public function aBareApplicationFormStaysClosed(): void
	{
		$form = new Form();
		$form->addText('epsilon');

		assertComponent($form, <<<'OUTPUT'
			Nette\Application\UI\Form{
			  epsilon: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	/**
	 * The second entry of that pair, which no corpus call site reaches — its whole proof is here.
	 */
	public function aBarePlainFormStaysClosed(): void
	{
		$form = new PlainForm();
		$form->addText('epsilon');

		assertComponent($form, <<<'OUTPUT'
			Nette\Forms\Form{
			  epsilon: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	public function heldInALocal(): void
	{
		$form = new ConstructedLocalForm();

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert\ConstructedLocalForm{
			  alpha: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  beta: Nette\Forms\Controls\Checkbox<bool|float|int|string|null, bool>,
			}
			OUTPUT);
	}

	/**
	 * The reported symptom itself: reading a constructor-added control off the local. Each of these
	 * was `Form component '…' does not exist` — a PROOF of absence about a control sitting in the
	 * constructor two lines up.
	 */
	public function constructorControlsAreReadableByOffset(): void
	{
		$form = new ConstructedLocalForm();

		assertComponent($form['alpha'], 'Nette\Forms\Controls\TextInput');
		assertComponent($form['beta'], 'Nette\Forms\Controls\Checkbox');
	}

	/**
	 * The spelling the local above is measured against, which has always resolved.
	 */
	public function returnedByAFactory(): void
	{
		$form = $this->factory->create();

		assertComponent($form['alpha'], 'Nette\Forms\Controls\TextInput');
		assertComponent($form['beta'], 'Nette\Forms\Controls\Checkbox');
	}

	public function composedWithLaterAdds(): void
	{
		$form = new ConstructedLocalForm();
		$form->addText('epsilon');

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert\ConstructedLocalForm{
			  alpha: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  beta: Nette\Forms\Controls\Checkbox<bool|float|int|string|null, bool>,
			  epsilon: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	/**
	 * The proof that nothing was blanket-opened: a class declaring no constructor of its own adds
	 * nothing — there is no body for it to have added anything in — so the shape stays CLOSED, which
	 * is what keeps every absence this walk reports on a plainly built form.
	 */
	public function noConstructorStaysClosed(): void
	{
		$form = new ConstructedLocalPlainForm();
		$form->addText('epsilon');

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert\ConstructedLocalPlainForm{
			  epsilon: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	/**
	 * A constructor calling a control-registering helper is not one ConstructorFormShapeResolver
	 * reads, so it DEGRADES: `delta` is neither claimed nor denied. Open is the answer an unreadable
	 * origin gets; empty-and-closed is the one it must never get.
	 */
	public function unreadableConstructorOpens(): void
	{
		$form = new ConstructedLocalHelperBuiltForm();

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert\ConstructedLocalHelperBuiltForm{
			  ...<IComponent>,
			}
			OUTPUT);
	}

}
