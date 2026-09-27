<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\TypeInference;

use Nette\Application\UI\Control;
use Nette\Application\UI\Form;
use Nette\Forms\Container;
use function PHPStan\Testing\assertType;

final class G9SeparatorPathOwnChildType extends Control
{

	protected function createComponentForm(): Form
	{
		$form = new Form();
		$outer = $form->addContainer('outer');
		$rep = $outer->addDynamic('rep', static function (Container $c): void {
			$c->addText('x');
		});
		$rep->addSubmit('addNode', 'Add');

		return $form;
	}

	public function render(): void
	{
		$form = $this['form'];
		assert($form instanceof Form);

		// The separator-joined path form ($form['outer']['rep-addNode']) is the same lookup as
		// $form['outer']['rep']['addNode'] at runtime (Nette's Container::getComponent() splits on
		// IComponent::NameSeparator) — this must resolve to the SAME own-child button type a direct,
		// un-joined access would, not a bare IComponent (which is missing getControlPart()/getLabel()/
		// getControl(), the exact false positive this fixture pins).
		assertType('Nette\Forms\Controls\SubmitButton', $form['outer']['rep-addNode']);

		// A path segment that cannot be proven (an unknown container/replicator name, or reaching
		// past a replicator's own child) degrades to the native ArrayAccess stub's answer rather than
		// a confidently-wrong guess.
		assertType('Nette\ComponentModel\IComponent', $form['outer']['nope-addNode']);
		assertType('Nette\ComponentModel\IComponent', $form['outer']['rep-addNode-deeper']);

		// DIAGNOSTIC: the exact shape Latte's n:name macro compiles a '$'-prefixed value to
		// (vendor/nette/forms/src/Bridges/FormsLatte/FormMacros.php macroNameAttr()/macroInput()):
		// is_object($tmp = EXPR) ? $tmp : end($this->global->formsStack)[$tmp] — the false branch is
		// a DYNAMIC (non-constant-string) offset, which can never be narrowed.
		$ternary = is_object($tmp = $form['outer']['rep-addNode']) ? $tmp : $form['outer'][$tmp];
		assertType('Nette\Forms\Controls\SubmitButton', $ternary);
	}

}
