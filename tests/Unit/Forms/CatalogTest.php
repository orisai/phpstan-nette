<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms;

use Nette\Forms\Controls\Checkbox;
use Nette\Forms\Controls\CheckboxList;
use Nette\Forms\Controls\ColorPicker;
use Nette\Forms\Controls\CsrfProtection;
use Nette\Forms\Controls\DateTimeControl;
use Nette\Forms\Controls\HiddenField;
use Nette\Forms\Controls\MultiSelectBox;
use Nette\Forms\Controls\RadioList;
use Nette\Forms\Controls\SelectBox;
use Nette\Forms\Controls\TextArea;
use Nette\Forms\Controls\TextInput;
use Nette\Forms\Controls\UploadControl;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Analyzer\CalleeShapeResolver;
use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use OriPhpstan\Nette\Forms\Catalog\ControlValueResolution;
use OriPhpstan\Nette\Forms\Catalog\NetteEffectiveControlValueTypeResolver;
use OriPhpstan\Nette\Forms\Catalog\Stub\ControlAnnotationValueTypeReader;
use OriPhpstan\Nette\Forms\Shape\UnknownReason;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Parser\Parser;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Testing\TypeInferenceTestCase;
use PHPStan\Type\Accessory\AccessoryArrayListType;
use PHPStan\Type\Accessory\AccessoryNonEmptyStringType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;
use function assert;
use function dirname;
use function is_dir;
use function sys_get_temp_dir;
use function uniqid;

final class CatalogTest extends TypeInferenceTestCase
{

	use VersionGroupGate;

	private NetteEffectiveControlValueTypeResolver $resolver;

	private string $cacheDir;

	/** @return list<string> */
	public static function getAdditionalConfigFiles(): array
	{
		return [dirname(__DIR__, 2) . '/Fixtures/Forms/Catalog/prototype.neon'];
	}

	protected function setUp(): void
	{
		parent::setUp();
		$parser = self::getContainer()->getService('currentPhpVersionRichParser');
		assert($parser instanceof Parser);
		$this->cacheDir = sys_get_temp_dir() . '/forms-catalog-test-' . uniqid('', true);
		$this->resolver = new NetteEffectiveControlValueTypeResolver(
			new ControlAnnotationValueTypeReader(
				self::createReflectionProvider(),
				self::getContainer()->getByType(TypeStringResolver::class),
			),
			new CalleeShapeResolver(
				$parser,
				self::createReflectionProvider(),
				new FormShapeCache($this->cacheDir),
			),
		);
	}

	protected function tearDown(): void
	{
		parent::tearDown();

		if (is_dir($this->cacheDir)) {
			FileSystem::delete($this->cacheDir);
		}
	}

	/** @return array<string, ControlValueResolution> */
	private function resolveFixture(): array
	{
		$resolver = $this->resolver;
		$results = [];

		self::processFile(
			dirname(__DIR__, 2) . '/Doubles/Forms/Catalog.php',
			static function (Node $node, Scope $scope) use ($resolver, &$results): void {
				if (!$node instanceof MethodCall || !$node->name instanceof Identifier) {
					return;
				}

				$methodName = $node->name->toString();
				$receiverType = $scope->getType($node->var);
				$resolution = $resolver->resolve($methodName, $receiverType, $node, $scope);
				$isForm = (new ObjectType('Nette\\Forms\\Container'))->isSuperTypeOf($receiverType)->yes();
				$key = $isForm ? $methodName : $methodName . '@nonform';
				$results[$key] = $resolution;
			},
		);

		return $results;
	}

	private function precise(?Type $type): string
	{
		self::assertNotNull($type);

		return $type->describe(VerbosityLevel::precise());
	}

	public function testCatalogResolvesEveryAddMethod(): void
	{
		$r = $this->resolveFixture();

		foreach (['addText', 'addPassword', 'addTextArea', 'addEmail', 'addColor'] as $m) {
			self::assertSame(ControlValueResolution::KIND_VALUE, $r[$m]->getKind(), $m);
			self::assertSame('string', $this->precise($r[$m]->getValueType()), $m);
		}

		self::assertSame(ControlValueResolution::KIND_VALUE, $r['addInteger']->getKind());
		self::assertSame(
			$this->precise(TypeCombinator::union(new IntegerType(), new NullType())),
			$this->precise($r['addInteger']->getValueType()),
		);

		self::assertSame(ControlValueResolution::KIND_VALUE, $r['addFloat']->getKind());
		self::assertSame(
			$this->precise(TypeCombinator::union(new FloatType(), new NullType())),
			$this->precise($r['addFloat']->getValueType()),
		);

		self::assertSame(ControlValueResolution::KIND_VALUE, $r['addCheckbox']->getKind());
		self::assertSame($this->precise(new BooleanType()), $this->precise($r['addCheckbox']->getValueType()));

		self::assertSame(ControlValueResolution::KIND_VALUE, $r['addHidden']->getKind());
		self::assertSame(
			$this->precise(TypeCombinator::union(new StringType(), new NullType())),
			$this->precise($r['addHidden']->getValueType()),
		);

		foreach (['addSelect', 'addRadioList'] as $m) {
			self::assertSame(ControlValueResolution::KIND_VALUE, $r[$m]->getKind(), $m);
			self::assertSame(
				$this->precise(TypeCombinator::union(new IntegerType(), new StringType(), new NullType())),
				$this->precise($r[$m]->getValueType()),
				$m,
			);
		}

		$listType = TypeCombinator::intersect(
			new ArrayType(new IntegerType(), TypeCombinator::union(new IntegerType(), new StringType())),
			new AccessoryArrayListType(),
		);
		foreach (['addMultiSelect', 'addCheckboxList'] as $m) {
			self::assertSame(ControlValueResolution::KIND_VALUE, $r[$m]->getKind(), $m);
			self::assertSame($this->precise($listType), $this->precise($r[$m]->getValueType()), $m);
		}

		self::assertSame(ControlValueResolution::KIND_VALUE, $r['addUpload']->getKind());
		self::assertSame(
			$this->precise(TypeCombinator::union(new ObjectType('Nette\\Http\\FileUpload'), new NullType())),
			$this->precise($r['addUpload']->getValueType()),
		);

		$multiUpload = TypeCombinator::union(
			TypeCombinator::intersect(
				new ArrayType(new IntegerType(), new ObjectType('Nette\\Http\\FileUpload')),
				new AccessoryArrayListType(),
			),
			new NullType(),
		);
		self::assertSame(ControlValueResolution::KIND_VALUE, $r['addMultiUpload']->getKind());
		self::assertSame($this->precise($multiUpload), $this->precise($r['addMultiUpload']->getValueType()));

		$dateType = TypeCombinator::union(new ObjectType('DateTimeImmutable'), new NullType());
		foreach (['addDate', 'addTime', 'addDateTime'] as $m) {
			self::assertSame(ControlValueResolution::KIND_VALUE, $r[$m]->getKind(), $m);
			self::assertSame($this->precise($dateType), $this->precise($r[$m]->getValueType()), $m);
		}

		self::assertSame(ControlValueResolution::KIND_CONTAINER, $r['addContainer']->getKind());
		self::assertNull($r['addContainer']->getValueType());

		self::assertSame(ControlValueResolution::KIND_REPLICATOR, $r['addDynamic']->getKind());
		self::assertNull($r['addDynamic']->getValueType());

		foreach (['addSubmit', 'addImageButton', 'addImage', 'addReCaptcha', 'addProtection'] as $m) {
			self::assertSame(ControlValueResolution::KIND_OMITTED, $r[$m]->getKind(), $m);
			self::assertNull($r[$m]->getValueType(), $m);
		}

		self::assertSame(CsrfProtection::class, $r['addProtection']->getControlClass());

		// A plain Button stays in getValues(), typed string|null via its @form-read-type catalog entry.
		self::assertSame(ControlValueResolution::KIND_VALUE, $r['addButton']->getKind());
		self::assertSame('string|null', $this->precise($r['addButton']->getValueType()));

		self::assertSame(ControlValueResolution::KIND_UNKNOWN_TYPE, $r['somethingUnknownAdding']->getKind());
		self::assertNull($r['somethingUnknownAdding']->getValueType());
		self::assertSame([UnknownReason::EXTENSION_METHOD], $r['somethingUnknownAdding']->getUnknownReasons());

		self::assertSame(ControlValueResolution::KIND_UNKNOWN_TYPE, $r['addText@nonform']->getKind());
		self::assertNull($r['addText@nonform']->getValueType());
		self::assertSame([], $r['addText@nonform']->getUnknownReasons());
	}

	/**
	 * The other two halves of what a vendor factory resolves to, which no longer come from one
	 * hardcoded table: the control CLASS is Nette's own declared return type, and the write spec is
	 * the ladder that class lands on unless the catalog overrides it. addInteger()/addFloat() are the
	 * whole reason the override exists — they build a TextInput the ladder alone would call `text`.
	 */
	public function testCatalogResolvesEveryAddMethodsControlClassAndWriteSpec(): void
	{
		$r = $this->resolveFixture();

		$expected = [
			'addText' => [TextInput::class, 'text'],
			'addPassword' => [TextInput::class, 'text'],
			'addEmail' => [TextInput::class, 'text'],
			'addTextArea' => [TextArea::class, 'text'],
			'addInteger' => [TextInput::class, 'integer'],
			'addFloat' => [TextInput::class, 'float'],
			'addCheckbox' => [Checkbox::class, 'checkbox'],
			'addHidden' => [HiddenField::class, 'hidden'],
			'addSelect' => [SelectBox::class, 'choice'],
			'addRadioList' => [RadioList::class, 'choice'],
			'addMultiSelect' => [MultiSelectBox::class, 'multichoice'],
			'addCheckboxList' => [CheckboxList::class, 'multichoice'],
			'addUpload' => [UploadControl::class, 'upload'],
			'addMultiUpload' => [UploadControl::class, 'upload'],
			'addColor' => [ColorPicker::class, 'color'],
			'addDate' => [DateTimeControl::class, 'datetime'],
			'addTime' => [DateTimeControl::class, 'datetime'],
			'addDateTime' => [DateTimeControl::class, 'datetime'],
		];

		foreach ($expected as $method => [$controlClass, $setSpec]) {
			self::assertSame($controlClass, $r[$method]->getControlClass(), $method);
			self::assertSame($setSpec, $r[$method]->getAcceptedSetSpec(), $method);
		}
	}

	public function testApplyNullableWidensValueKind(): void
	{
		$applied = ControlValueResolution::applyNullable(
			new ControlValueResolution(ControlValueResolution::KIND_VALUE, new StringType(), []),
		);
		self::assertSame(ControlValueResolution::KIND_VALUE, $applied->getKind());
		self::assertSame(
			$this->precise(TypeCombinator::union(
				TypeCombinator::intersect(new StringType(), new AccessoryNonEmptyStringType()),
				new NullType(),
			)),
			$this->precise($applied->getValueType()),
		);

		$alreadyNullable = new ControlValueResolution(
			ControlValueResolution::KIND_VALUE,
			TypeCombinator::union(new StringType(), new NullType()),
			[],
		);
		$idempotent = ControlValueResolution::applyNullable($alreadyNullable);
		self::assertSame(
			$this->precise(TypeCombinator::union(new StringType(), new NullType())),
			$this->precise($idempotent->getValueType()),
		);
	}

	public function testApplyNullableLeavesNonValueUnchanged(): void
	{
		$omitted = new ControlValueResolution(ControlValueResolution::KIND_OMITTED, null, []);
		self::assertSame($omitted, ControlValueResolution::applyNullable($omitted));
	}

}
