<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Catalog\Stub;

/**
 * The METHOD-keyed facts about Nette's own control factories, in one declarative place.
 *
 * Method-keyed and not class-keyed because the value type genuinely is: addText(), addPassword()
 * and addEmail() all return a TextInput reading string, addInteger() returns one reading int|null
 * and addFloat() one reading float|null — one control class, three value types. addUpload() and
 * addMultiUpload() are the same story on UploadControl. A class-keyed reading cannot express that,
 * which is why this catalog exists at all and why every factory is listed rather than only the ones
 * that disagree: the list is also what says "this vendor method is a VALUE factory", as against
 * addContainer(), addSubmit() or addButton().
 *
 * What is deliberately NOT here is the control CLASS. That is read live off
 * Nette\Forms\Container::<method>()'s own declared return type, so nothing restates it.
 *
 * Why a synthetic interface rather than a stub of Nette\Forms\Container, which would let vendor and
 * user code share one mechanism: phpstan/phpstan-nette already ships stubs/Forms/Container.stub,
 * and a second stub of the same class makes PHPStan emit a NON-IGNORABLE `class.duplicate` — the
 * gate can never be green again. See docs/forms-static-analysis.md, "Why the vendor factories are
 * not delivered as a Container stub".
 *
 * The names are checked against the installed vendor source by VendorCatalogFreshness, so a factory
 * Nette renames or drops fails a `make` target instead of silently going untyped.
 */
interface FormValueTypeCatalog
{

	/** @form-read-type string */
	public function addText(): void;

	/** @form-read-type string */
	public function addPassword(): void;

	/** @form-read-type string */
	public function addEmail(): void;

	/** @form-read-type string */
	public function addTextArea(): void;

	/**
	 * @form-read-type int|null
	 * @form-write-spec integer
	 */
	public function addInteger(): void;

	/**
	 * @form-read-type float|null
	 * @form-write-spec float
	 */
	public function addFloat(): void;

	/** @form-read-type string */
	public function addColor(): void;

	/** @form-read-type bool */
	public function addCheckbox(): void;

	/** @form-read-type string|null */
	public function addHidden(): void;

	/** @form-read-type int|string|null */
	public function addSelect(): void;

	/** @form-read-type int|string|null */
	public function addRadioList(): void;

	/** @form-read-type list<int|string> */
	public function addMultiSelect(): void;

	/** @form-read-type list<int|string> */
	public function addCheckboxList(): void;

	/** @form-read-type Nette\Http\FileUpload|null */
	public function addUpload(): void;

	/** @form-read-type list<Nette\Http\FileUpload>|null */
	public function addMultiUpload(): void;

	/** @form-read-type DateTimeImmutable|null */
	public function addDate(): void;

	/** @form-read-type DateTimeImmutable|null */
	public function addTime(): void;

	/** @form-read-type DateTimeImmutable|null */
	public function addDateTime(): void;

}
