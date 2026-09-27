<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Type;

use Nette\Utils\ArrayHash;
use stdClass;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;
use function PHPStan\Testing\assertType;

final class GetValues
{

	public function g3_01(): void
	{
		$form = new ApplicationForm();
		$form->addText('p');
		assertType('Nette\Utils\ArrayHash{p: string}', $form->getValues());
	}

	public function g3_02(): void
	{
		$form = new ApplicationForm();
		$form->addText('p');
		assertType('string', $form->getValues()->p);
	}

	public function g3_03(): void
	{
		$form = new ApplicationForm();
		$form->addText('p');
		assertType('string', $form->getValues()['p']);
	}

	public function g3_04(): void
	{
		$form = new ApplicationForm();
		$form->addInteger('p');
		assertType('int|null', $form->getValues()->p);
	}

	public function g3_05(): void
	{
		$form = new ApplicationForm();
		$a = $form->addContainer('a');
		$a->addText('b');
		assertType('Nette\Utils\ArrayHash{a: Nette\Utils\ArrayHash{b: string}}', $form->getValues());
	}

	public function g3_06(): void
	{
		$form = new ApplicationForm();
		$a = $form->addContainer('a');
		$a->addText('b');
		assertType('string', $form->getValues()->a->b);
	}

	public function g3_07(): void
	{
		$form = new ApplicationForm();
		$a = $form->addContainer('a');
		$a->addText('b');
		assertType('string', $form->getValues()['a']['b']);
	}

	public function g3_08(): void
	{
		$form = new ApplicationForm();
		$a = $form->addContainer('a');
		$a->addText('b');
		assertType('string', $form->getValues()->a['b']);
	}

	public function g3_09(): void
	{
		$form = new ApplicationForm();
		$a = $form->addContainer('a');
		$a->addText('b');
		assertType('string', $form->getValues()['a']->b);
	}

	public function g3_10(): void
	{
		$form = new ApplicationForm();
		$form->addDynamic('d', fn (FormContainer $c) => $c->addText('a'));
		assertType('Nette\Utils\ArrayHash{d: Nette\Utils\ArrayHash<Nette\Utils\ArrayHash{a: string}>}', $form->getValues());
	}

	public function g3_11(): void
	{
		$form = new ApplicationForm();
		$form->addDynamic('d', fn (FormContainer $c) => $c->addText('a'));
		assertType('Nette\Utils\ArrayHash<Nette\Utils\ArrayHash{a: string}>', $form->getValues()->d);
	}

	public function g3_12(bool $c): void
	{
		$form = new ApplicationForm();
		if ($c) {
			$form->addText('p');
		}

		assertType('Nette\Utils\ArrayHash{p: string|null}', $form->getValues());
	}

	public function g3_13(bool $c): void
	{
		$form = new ApplicationForm();
		if ($c) {
			$form->addText('p');
		}

		assertType('string|null', $form->getValues()->p);
	}

	public function g3_14(string $name): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$form->addText($name);
		assertType('Nette\Utils\ArrayHash{a: string, ...<mixed>}', $form->getValues());
	}

	/**
	 * The values channel reads the same container/replicator presence the offset channel does
	 * (FormValuesProjector::projectObject()), so a presence wrongly promoted by a later branch
	 * join dropped the null arm here too: `$values->c` came out non-nullable on a container that
	 * is absent whenever $c is false. The second `if` touches nothing - it only forces the join.
	 */
	public function g3_15(bool $c, bool $d): void
	{
		$form = new ApplicationForm();
		if ($c) {
			$inner = $form->addContainer('c');
			$inner->addText('a');
		}

		if ($d) {
			$form->addText('unrelated');
		}

		assertType('Nette\Utils\ArrayHash{a: string}|null', $form->getValues()->c);
	}

	/**
	 * Naming the CRATE explicitly is the same call as taking the default one, and it used to be the
	 * spelling that lost the shape on this channel: every class-string argument was answered with the
	 * bare class, so `stdClass`/`ArrayHash` came back with no fields while the no-arg spelling one line
	 * up kept them. FormValuesProjector::isUniversalCrate() is what separates a crate Nette fills with
	 * the form's own fields from a mapped DTO that declares its own (MappedType::g5_01() pins the DTO
	 * half, which must stay the bare class), and both getValues() channels ask it.
	 */
	public function g3_16(): void
	{
		$form = new ApplicationForm();
		$form->addText('p');
		assertType('Nette\Utils\ArrayHash{p: string}', $form->getValues(ArrayHash::class));
	}

	public function g3_17(): void
	{
		$form = new ApplicationForm();
		$form->addText('p');
		assertType('stdClass{p: string}', $form->getValues(stdClass::class));
	}

	public function g3_18(): void
	{
		$form = new ApplicationForm();
		$form->addText('p');
		$form->addCheckbox('q');
		assertType('string', $form->getValues(stdClass::class)->p);
		assertType('bool', $form->getValues(stdClass::class)['q']);
	}

	/**
	 * getUntrustedValues() takes the crate argument on the same terms; what it never takes is the
	 * form's own setMappedType(), which is Nette's own asymmetry and is pinned by MappedType::g5_08().
	 */
	public function g3_19(): void
	{
		$form = new ApplicationForm();
		$form->addText('p');
		assertType('stdClass{p: string}', $form->getUntrustedValues(stdClass::class));
	}

	/**
	 * A class-string the analysis cannot pin to one class has no crate to choose, so the bare object
	 * type stays - the degrade, unchanged.
	 *
	 * @param class-string $class
	 */
	public function g3_20(string $class): void
	{
		$form = new ApplicationForm();
		$form->addText('p');
		assertType('object', $form->getValues($class));
	}

}
