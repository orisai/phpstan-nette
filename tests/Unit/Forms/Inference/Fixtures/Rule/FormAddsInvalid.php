<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Nette\Forms\Controls\SelectBox;
use Nette\Forms\Controls\TextInput;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm;

final class FormAddsInvalid extends RawForm
{

	/**
	 * @form-adds name
	 */
	public function addMalformed(string $name): TextInput
	{
		return $this->addText($name);
	}

	/**
	 * @form-adds $missing
	 */
	public function addUnknownParameter(string $name): TextInput
	{
		return $this->addText($name);
	}

	/**
	 * @form-adds $name
	 * @form-adds $name
	 */
	public function addDuplicateParameter(string $name): TextInput
	{
		return $this->addText($name);
	}

	/**
	 * A short class name is not resolved against this file's use statements.
	 *
	 * @form-adds $name TextInput
	 */
	public function addUnqualifiedClass(string $name): void
	{
		$this->addText($name);
	}

	/**
	 * @form-adds $name DateTimeImmutable
	 */
	public function addNonComponentClass(string $name): void
	{
		$this->addText($name);
	}

	/**
	 * @form-adds $name Nette\Forms\Controls\TextInput
	 */
	public function addContradictingReturnType(string $name): SelectBox
	{
		return $this->addSelect($name);
	}

	/**
	 * @form-adds $name
	 */
	public function addNoClassAnywhere(string $name): void
	{
		$this->addText($name);
	}

}

final class FormAddsNotAContainer
{

	/**
	 * @form-adds $name Nette\Forms\Controls\TextInput
	 */
	public function addThing(string $name): void
	{
	}

}

/**
 * @form-adds $name Nette\Forms\Controls\TextInput
 */
function formAddsFreeFunction(string $name): void
{
}
