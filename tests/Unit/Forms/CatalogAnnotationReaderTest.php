<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms;

use DateTimeImmutable;
use Nette\Forms\Container;
use Nette\Forms\Controls\Checkbox;
use Nette\Forms\Controls\CheckboxList;
use Nette\Forms\Controls\ColorPicker;
use Nette\Forms\Controls\DateTimeControl;
use Nette\Forms\Controls\HiddenField;
use Nette\Forms\Controls\MultiSelectBox;
use Nette\Forms\Controls\RadioList;
use Nette\Forms\Controls\SelectBox;
use Nette\Forms\Controls\TextArea;
use Nette\Forms\Controls\TextInput;
use Nette\Forms\Controls\UploadControl;
use Nette\Http\FileUpload;
use OriPhpstan\Nette\Forms\Catalog\Stub\ControlAnnotationValueTypeReader;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\Accessory\AccessoryArrayListType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;
use Tests\OriPhpstan\Nette\Unit\Forms\Support\AnnotatedAddsContainer;
use Tests\OriPhpstan\Nette\Unit\Forms\Support\AnnotatedChoiceControl;
use Tests\OriPhpstan\Nette\Unit\Forms\Support\AnnotatedCustomControl;
use Tests\OriPhpstan\Nette\Unit\Forms\Support\AnnotatedReplicatorContainer;
use Tests\OriPhpstan\Nette\Unit\Forms\Support\AnnotatedWizard;
use Tests\OriPhpstan\Nette\Unit\Forms\Support\CustomStepPrefixWizard;
use Tests\OriPhpstan\Nette\Unit\Forms\Support\InheritedWizard;
use function dirname;

final class CatalogAnnotationReaderTest extends PHPStanTestCase
{

	/** @return list<string> */
	public static function getAdditionalConfigFiles(): array
	{
		return [dirname(__DIR__, 2) . '/Fixtures/Forms/Catalog/prototype.neon'];
	}

	private function reader(): ControlAnnotationValueTypeReader
	{
		return new ControlAnnotationValueTypeReader(
			self::createReflectionProvider(),
			self::getContainer()->getByType(TypeStringResolver::class),
		);
	}

	private function precise(?Type $type): string
	{
		self::assertNotNull($type);

		return $type->describe(VerbosityLevel::precise());
	}

	public function testAddMethodTagsReadFromReflectionEqualHardcoded(): void
	{
		$reader = $this->reader();

		$string = $reader->readTypeForAddMethod('addText');
		self::assertNotNull($string, 'addText must resolve via reflection, not hardcoded fallback');
		self::assertSame($this->precise(new StringType()), $this->precise($string));

		$bool = $reader->readTypeForAddMethod('addCheckbox');
		self::assertNotNull($bool, 'addCheckbox must resolve via reflection');
		self::assertSame('bool', $this->precise($bool));

		$choice = $reader->readTypeForAddMethod('addSelect');
		self::assertNotNull($choice, 'addSelect must resolve via reflection');
		self::assertSame(
			$this->precise(TypeCombinator::union(new IntegerType(), new StringType(), new NullType())),
			$this->precise($choice),
		);
	}

	public function testClassTagsReadFromReflectionEqualHardcoded(): void
	{
		$reader = $this->reader();

		$choice = TypeCombinator::union(new IntegerType(), new StringType(), new NullType());
		$listIntString = TypeCombinator::intersect(
			new ArrayType(new IntegerType(), TypeCombinator::union(new IntegerType(), new StringType())),
			new AccessoryArrayListType(),
		);

		self::assertSame('string', $this->precise($reader->readTypeForClass(TextInput::class)));
		self::assertSame('string', $this->precise($reader->readTypeForClass(TextArea::class)));
		self::assertSame('string', $this->precise($reader->readTypeForClass(ColorPicker::class)));
		self::assertSame('bool', $this->precise($reader->readTypeForClass(Checkbox::class)));
		self::assertSame(
			$this->precise(TypeCombinator::union(new StringType(), new NullType())),
			$this->precise($reader->readTypeForClass(HiddenField::class)),
		);
		self::assertSame($this->precise($choice), $this->precise($reader->readTypeForClass(SelectBox::class)));
		self::assertSame($this->precise($choice), $this->precise($reader->readTypeForClass(RadioList::class)));
		self::assertSame(
			$this->precise($listIntString),
			$this->precise($reader->readTypeForClass(MultiSelectBox::class)),
		);
		self::assertSame(
			$this->precise($listIntString),
			$this->precise($reader->readTypeForClass(CheckboxList::class)),
		);
		self::assertSame(
			$this->precise(TypeCombinator::union(new ObjectType(FileUpload::class), new NullType())),
			$this->precise($reader->readTypeForClass(UploadControl::class)),
		);
		self::assertSame(
			$this->precise(TypeCombinator::union(new ObjectType(DateTimeImmutable::class), new NullType())),
			$this->precise($reader->readTypeForClass(DateTimeControl::class)),
		);
	}

	public function testUploadValueIsStructuredFileUploadUnion(): void
	{
		$resolver = self::getContainer()->getByType(TypeStringResolver::class);
		$expected = TypeCombinator::union(new ObjectType('Nette\\Http\\FileUpload'), new NullType());
		self::assertSame(
			$this->precise($expected),
			$this->precise($resolver->resolve('Nette\\Http\\FileUpload|null')),
		);
	}

	public function testIntegerAddMethodTagResolves(): void
	{
		self::assertSame(
			$this->precise(TypeCombinator::union(new IntegerType(), new NullType())),
			$this->precise($this->reader()->readTypeForAddMethod('addInteger')),
		);
	}

	/**
	 * The control class behind a vendor factory is Nette's own declared return type, read live —
	 * nothing restates it. Every factory the value catalog types has to answer here, including the
	 * ones whose value type disagrees with what their class alone would say.
	 */
	public function testVendorControlClassComesFromNettesOwnReturnType(): void
	{
		$reader = $this->reader();

		self::assertSame(TextInput::class, $reader->controlClassForAddMethod('addText'));
		self::assertSame(TextInput::class, $reader->controlClassForAddMethod('addInteger'));
		self::assertSame(TextArea::class, $reader->controlClassForAddMethod('addTextArea'));
		self::assertSame(SelectBox::class, $reader->controlClassForAddMethod('addSelect'));
		self::assertSame(MultiSelectBox::class, $reader->controlClassForAddMethod('addMultiSelect'));
		self::assertSame(RadioList::class, $reader->controlClassForAddMethod('addRadioList'));
		self::assertSame(CheckboxList::class, $reader->controlClassForAddMethod('addCheckboxList'));
		self::assertSame(Checkbox::class, $reader->controlClassForAddMethod('addCheckbox'));
		self::assertSame(HiddenField::class, $reader->controlClassForAddMethod('addHidden'));
		self::assertSame(UploadControl::class, $reader->controlClassForAddMethod('addUpload'));
		self::assertSame(UploadControl::class, $reader->controlClassForAddMethod('addMultiUpload'));
		self::assertSame(DateTimeControl::class, $reader->controlClassForAddMethod('addDate'));
		self::assertSame(ColorPicker::class, $reader->controlClassForAddMethod('addColor'));

		self::assertNull($reader->controlClassForAddMethod('addTheresNoSuchFactory'));
		self::assertNull($reader->controlClassForAddMethod('isValid'));

		// Returning a component is NOT what makes a method a value factory — addContainer() answers
		// here too, and is kept out of the value branch by having no catalog entry.
		self::assertSame(Container::class, $reader->controlClassForAddMethod('addContainer'));
		self::assertNull($reader->readTypeForAddMethod('addContainer'));
	}

	/**
	 * addInteger()/addFloat() build a TextInput, which the class ladder alone would call `text`; the
	 * write spec is the one vendor fact the class cannot supply, so it is declared beside the value
	 * type and nowhere else.
	 */
	public function testVendorWriteSpecOverridesOnlyWhereTheClassCannotSayIt(): void
	{
		$reader = $this->reader();

		self::assertSame('integer', $reader->writeSpecForAddMethod('addInteger'));
		self::assertSame('float', $reader->writeSpecForAddMethod('addFloat'));
		self::assertNull($reader->writeSpecForAddMethod('addText'));
		self::assertNull($reader->writeSpecForAddMethod('addSelect'));
		self::assertNull($reader->writeSpecForAddMethod('addTheresNoSuchFactory'));
	}

	public function testCustomControlWriteTypeReadFromClassTag(): void
	{
		$reader = $this->reader();

		self::assertSame("'a'|'b'|'c'", $reader->writeTypeForClass(AnnotatedCustomControl::class));
		self::assertNull($reader->writeTypeForClass(TextInput::class));
	}

	public function testCustomModifierEffectReadFromMethodTag(): void
	{
		$reader = $this->reader();

		self::assertSame(
			ControlAnnotationValueTypeReader::MODIFIER_NULLABLE,
			$reader->modifierEffectForMethod(AnnotatedCustomControl::class, 'asNullable'),
		);
		self::assertSame(
			ControlAnnotationValueTypeReader::MODIFIER_REQUIRED,
			$reader->modifierEffectForMethod(AnnotatedCustomControl::class, 'asRequired'),
		);
		self::assertNull($reader->modifierEffectForMethod(AnnotatedCustomControl::class, 'unknownEffect'));
		self::assertNull($reader->modifierEffectForMethod(AnnotatedCustomControl::class, 'untagged'));
		self::assertNull($reader->modifierEffectForMethod(AnnotatedCustomControl::class, 'missingMethod'));
	}

	public function testNativeChoiceModelsReadFromTags(): void
	{
		$reader = $this->reader();

		$select = $reader->choiceModelForClass(SelectBox::class);
		self::assertNotNull($select);
		self::assertFalse($select->isMulti());
		self::assertFalse($select->isOpen());
		self::assertSame('int|string', $this->precise($select->getKeyDomain()));
		self::assertNull($select->getExtra());

		$radio = $reader->choiceModelForClass(RadioList::class);
		self::assertNotNull($radio);
		self::assertFalse($radio->isMulti());
		self::assertFalse($radio->isOpen());

		$multi = $reader->choiceModelForClass(MultiSelectBox::class);
		self::assertNotNull($multi);
		self::assertTrue($multi->isMulti());
		self::assertFalse($multi->isOpen());
		self::assertSame('int|string', $this->precise($multi->getKeyDomain()));

		$checkboxList = $reader->choiceModelForClass(CheckboxList::class);
		self::assertNotNull($checkboxList);
		self::assertTrue($checkboxList->isMulti());
		self::assertFalse($checkboxList->isOpen());
	}

	public function testNonChoiceControlHasNoChoiceModel(): void
	{
		$reader = $this->reader();

		self::assertNull($reader->choiceModelForClass(TextInput::class));
		self::assertNull($reader->choiceModelForClass(Checkbox::class));
	}

	public function testCustomOpenChoiceModelReadFromClassTag(): void
	{
		$model = $this->reader()->choiceModelForClass(AnnotatedChoiceControl::class);

		self::assertNotNull($model);
		self::assertFalse($model->isMulti());
		self::assertTrue($model->isOpen());
		self::assertSame('int', $this->precise($model->getKeyDomain()));
		self::assertSame('string', $this->precise($model->getExtra()));
	}

	public function testReplicatorMetaForKdybyAddMethodReadFromCatalog(): void
	{
		$meta = $this->reader()->replicatorMetaForAddMethod('addDynamic');

		self::assertNotNull($meta);
		self::assertSame(1, $meta->getFactoryArgPosition());
		self::assertSame('Kdyby\\Replicator\\Container', $meta->getContainerClass());
	}

	public function testReplicatorMetaForUnlistedAddMethodIsNull(): void
	{
		self::assertNull($this->reader()->replicatorMetaForAddMethod('addMultiplier'));
		self::assertNull($this->reader()->replicatorMetaForAddMethod('addText'));
	}

	public function testReplicatorMetaForClassReadFromOwnTag(): void
	{
		$meta = $this->reader()->replicatorMetaForClass(AnnotatedReplicatorContainer::class);

		self::assertNotNull($meta);
		self::assertSame(1, $meta->getFactoryArgPosition());
		self::assertSame(AnnotatedReplicatorContainer::class, $meta->getContainerClass());
	}

	public function testReplicatorMetaForUntaggedClassIsNull(): void
	{
		self::assertNull($this->reader()->replicatorMetaForClass(TextInput::class));
	}

	public function testWizardMetaForOwnTagDefaultsStepPrefix(): void
	{
		$meta = $this->reader()->wizardMetaForClass(AnnotatedWizard::class);

		self::assertNotNull($meta);
		self::assertSame('createStep', $meta->getStepMethodPrefix());
	}

	public function testWizardMetaInheritedFromParentTag(): void
	{
		$meta = $this->reader()->wizardMetaForClass(InheritedWizard::class);

		self::assertNotNull($meta);
		self::assertSame('createStep', $meta->getStepMethodPrefix());
	}

	public function testWizardMetaReadsCustomStepPrefix(): void
	{
		$meta = $this->reader()->wizardMetaForClass(CustomStepPrefixWizard::class);

		self::assertNotNull($meta);
		self::assertSame('buildStage', $meta->getStepMethodPrefix());
	}

	public function testWizardMetaForUntaggedClassIsNull(): void
	{
		self::assertNull($this->reader()->wizardMetaForClass(TextInput::class));
	}

	/** @return list<array{string, int, string|null}> */
	private function addsSpecs(string $methodName): array
	{
		$reader = $this->reader();
		$class = self::createReflectionProvider()->getClass(AnnotatedAddsContainer::class);

		$specs = [];
		foreach ($reader->addsSpecsForMethod($class->getNativeMethod($methodName)) as $spec) {
			$specs[] = [$spec->getParameterName(), $spec->getParameterIndex(), $spec->getControlClass()];
		}

		return $specs;
	}

	/**
	 * An omitted class operand is filled from the declared return type rather than left for the
	 * caller to rediscover, so the two sources of one class produce one spec and the model cannot
	 * resolve the explicit spelling any differently from the omission.
	 */
	public function testAddsTagOnOneLineTakesItsClassFromTheDeclaredReturnType(): void
	{
		self::assertSame([['name', 0, 'Nette\Forms\Controls\TextInput']], $this->addsSpecs('addSingleLine'));
	}

	/**
	 * The one case nothing fills: no class operand and no declared return either. The tag stays
	 * valid — the body read can still name a class — and the spec carries none.
	 */
	public function testAddsTagOnAnUndeclaredReturnCarriesNoClass(): void
	{
		self::assertSame([['name', 0, null]], $this->addsSpecs('addUndeclaredReturn'));
	}

	public function testAddsTagResolvesParameterIndexAndClass(): void
	{
		self::assertSame(
			[['name', 1, 'Nette\Forms\Controls\TextArea']],
			$this->addsSpecs('addSecondParameter'),
		);
	}

	public function testAddsTagIsRepeatableAndLeadingBackslashIsStripped(): void
	{
		self::assertSame(
			[
				['first', 0, 'Nette\Forms\Controls\TextInput'],
				['second', 1, 'Nette\Forms\Controls\SelectBox'],
			],
			$this->addsSpecs('addPair'),
		);
	}

	public function testTagWhoseNameOnlyStartsWithAddsIsNotRead(): void
	{
		self::assertSame([], $this->addsSpecs('addLookalike'));
		self::assertSame([], $this->addsSpecs('addUntagged'));
	}

}
