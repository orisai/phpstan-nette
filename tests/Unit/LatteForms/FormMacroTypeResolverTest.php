<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms;

use Nette\Forms\Controls\CheckboxList;
use Nette\Forms\Controls\TextInput;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use OriPhpstan\Nette\Forms\Catalog\Stub\ControlAnnotationValueTypeReader;
use OriPhpstan\Nette\Forms\Index\FileFactIndex;
use OriPhpstan\Nette\Forms\Index\IndexShapeResolver;
use OriPhpstan\Nette\Forms\Index\RegistrationIndex;
use OriPhpstan\Nette\Forms\Index\RegistrationRecognizer;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Forms\Shape\UnknownReason;
use OriPhpstan\Nette\Forms\Type\FormShapeType;
use OriPhpstan\Nette\Latte\Bridge\Discovery\CandidatePath;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Runtime\Helpers;
use OriPhpstan\Nette\LatteForms\FormMacroCollector;
use OriPhpstan\Nette\LatteForms\FormMacroTypeResolver;
use OriPhpstan\Nette\LatteForms\FormPairing;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\CollectedDataEmitter;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Analyser\Scope;
use PHPStan\Parser\Parser;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Type\ObjectType;
use Tests\OriPhpstan\Nette\Toolkit\FormShapeTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;
use Tests\OriPhpstan\Nette\Toolkit\TestFileFinder;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\ClosedRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\LostFieldRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\NestedMutatedRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\PairingForm;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\TypedNoFormRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\TypedOtherRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\TypedRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\TypeMutatedRenderer;
use function array_keys;
use function assert;
use function getcwd;
use function is_dir;
use function sys_get_temp_dir;
use function uniqid;

final class FormMacroTypeResolverTest extends FormShapeTestCase
{

	private const RENDERERS = __DIR__ . '/Fixtures/Renderer';

	private const TEMPLATES = __DIR__ . '/Fixtures/Rule';

	private const TYPING = 'typing.latte';

	private const CONTAINERS = 'typing-containers.latte';

	private const MUTATED = 'typing-mutated.latte';

	private const INCOMPLETE = 'typing-incomplete.latte';

	private const DEEP = 'typing-deep.latte';

	private const HOLLOW = 'typing-hollow.latte';

	private const LOST = 'typing-lost.latte';

	private const PROTECTED = 'typing-protected.latte';

	private const NESTED_MUTATED = 'typing-nested-mutated.latte';

	/** @var list<string> */
	private array $dirs = [];

	protected function tearDown(): void
	{
		parent::tearDown();
		foreach ($this->dirs as $dir) {
			if (is_dir($dir)) {
				FileSystem::delete($dir);
			}
		}
	}

	public function testFormVariableCarriesTheSingleResolvedShape(): void
	{
		$type = $this->resolver([self::TYPING => [TypedRenderer::class]])
			->formShapeFor(self::TYPING, 'choiceForm', $this->scope());

		self::assertInstanceOf(FormShapeType::class, $type);
		self::assertSame(PairingForm::class, $type->getClassName());
		self::assertArrayHasKey('years', $type->getFormShape()->getSlots());
	}

	public function testTwoResolvedFormsLeaveTheFormVariableWide(): void
	{
		self::assertNull(
			$this->resolver([self::TYPING => [TypedRenderer::class, TypedOtherRenderer::class]])
				->formShapeFor(self::TYPING, 'choiceForm', $this->scope()),
		);
	}

	public function testControlTakesTheBuilderClass(): void
	{
		$resolver = $this->resolver([self::TYPING => [TypedRenderer::class]]);

		self::assertSame(CheckboxList::class, $resolver->controlClassAt(self::TYPING, 2, 'years', $this->scope()));
		self::assertSame(TextInput::class, $resolver->controlClassAt(self::TYPING, 3, 'name', $this->scope()));
		self::assertSame(CheckboxList::class, $resolver->controlClassAt(self::TYPING, 5, 'years', $this->scope()));
	}

	public function testDynamicReferenceStaysUntyped(): void
	{
		self::assertNull(
			$this->resolver([self::TYPING => [TypedRenderer::class]])
				->controlClassAt(self::TYPING, 4, 'dynamic', $this->scope()),
		);
	}

	public function testLineWithoutAReferenceStaysUntyped(): void
	{
		self::assertNull(
			$this->resolver([self::TYPING => [TypedRenderer::class]])
				->controlClassAt(self::TYPING, 6, 'years', $this->scope()),
		);
	}

	public function testDisagreeingRenderersLeaveTheControlUntyped(): void
	{
		$resolver = $this->resolver([self::TYPING => [TypedRenderer::class, TypedOtherRenderer::class]]);

		self::assertNull($resolver->controlClassAt(self::TYPING, 2, 'years', $this->scope()));
		self::assertSame(TextInput::class, $resolver->controlClassAt(self::TYPING, 3, 'name', $this->scope()));
	}

	public function testUnresolvedRendererLeavesEverythingUntyped(): void
	{
		$resolver = $this->resolver([self::TYPING => [TypedRenderer::class, TypedNoFormRenderer::class]]);

		self::assertNull($resolver->formShapeFor(self::TYPING, 'choiceForm', $this->scope()));
		self::assertNull($resolver->controlClassAt(self::TYPING, 2, 'years', $this->scope()));
	}

	public function testContainerPathIsHonouredForBothSpellings(): void
	{
		$resolver = $this->resolver([self::CONTAINERS => [ClosedRenderer::class]]);

		self::assertSame(TextInput::class, $resolver->controlClassAt(self::CONTAINERS, 2, 'street', $this->scope()));
		self::assertSame(
			TextInput::class,
			$resolver->controlClassAt(self::CONTAINERS, 3, 'address-street', $this->scope()),
		);
		self::assertNull($resolver->controlClassAt(self::CONTAINERS, 4, 'street', $this->scope()));
	}

	public function testExternallyMutatedFormIsHandedOutAsAPlainObject(): void
	{
		$resolver = $this->resolver([self::MUTATED => [TypeMutatedRenderer::class]]);
		$type = $resolver->formShapeFor(self::MUTATED, 'typedForm', $this->scope());

		self::assertInstanceOf(ObjectType::class, $type);
		self::assertNotInstanceOf(FormShapeType::class, $type);
		self::assertSame(PairingForm::class, $type->getClassName());
		self::assertNull($resolver->controlClassAt(self::MUTATED, 1, 'email', $this->scope()));
	}

	public function testIncompleteFormIsHandedOutOpen(): void
	{
		$type = $this->resolver([self::INCOMPLETE => [TypedRenderer::class]])
			->formShapeFor(self::INCOMPLETE, 'buttonOnlyForm', $this->scope());

		self::assertInstanceOf(FormShapeType::class, $type);
		self::assertContains(UnknownReason::UNPROVEN_COMPLETE, $type->getFormShape()->getUnknown()->getReasons());
	}

	public function testClosedFormShapeCarriesNoUnknownReason(): void
	{
		$type = $this->resolver([self::TYPING => [TypedRenderer::class]])
			->formShapeFor(self::TYPING, 'choiceForm', $this->scope());

		self::assertInstanceOf(FormShapeType::class, $type);
		self::assertSame([], $type->getFormShape()->getUnknown()->getReasons());
	}

	public function testIncompleteNestedContainerIsHandedOutOpen(): void
	{
		$deep = $this->resolver([self::DEEP => [ClosedRenderer::class]])
			->formShapeFor(self::DEEP, 'deepForm', $this->scope());

		self::assertInstanceOf(FormShapeType::class, $deep);
		self::assertSame(
			[],
			$deep->getFormShape()->getContainers()['outer']->getContainers()['inner']->getUnknown()->getReasons(),
		);

		$hollow = $this->resolver([self::HOLLOW => [TypedRenderer::class]])
			->formShapeFor(self::HOLLOW, 'hollowForm', $this->scope());

		self::assertInstanceOf(FormShapeType::class, $hollow);
		self::assertSame([], $hollow->getFormShape()->getUnknown()->getReasons());
		self::assertArrayHasKey('empty', $hollow->getFormShape()->getContainers());
		self::assertContains(
			UnknownReason::UNPROVEN_COMPLETE,
			$hollow->getFormShape()->getContainers()['empty']->getUnknown()->getReasons(),
		);
	}

	public function testShapeWithItsOwnUnknownReasonIsHandedOutUnchanged(): void
	{
		$pairing = new FormPairing($this->storeWith([self::LOST => [LostFieldRenderer::class]]), $this->makeResolver());
		$pairing->bindScope($this->scope());
		$forms = $pairing->formsFor(self::LOST, 'lostForm');
		self::assertCount(1, $forms);
		$ownReasons = $forms[0]->getShape()->getUnknown()->getReasons();
		self::assertNotSame([], $ownReasons);

		$type = $this->resolver([self::LOST => [LostFieldRenderer::class]])
			->formShapeFor(self::LOST, 'lostForm', $this->scope());

		self::assertInstanceOf(FormShapeType::class, $type);
		self::assertSame($ownReasons, $type->getFormShape()->getUnknown()->getReasons());
		self::assertNotContains(UnknownReason::UNPROVEN_COMPLETE, $type->getFormShape()->getUnknown()->getReasons());
	}

	public function testNameAxisOnlyUnknownIsHandedOutOpen(): void
	{
		$pairing = new FormPairing(
			$this->storeWith([self::PROTECTED => [TypedRenderer::class]]),
			$this->makeResolver(),
		);
		$pairing->bindScope($this->scope());
		$forms = $pairing->formsFor(self::PROTECTED, 'protectedForm');
		self::assertCount(1, $forms);
		self::assertTrue($forms[0]->getShape()->getUnknown()->hasNameUnknown());
		self::assertFalse($forms[0]->getShape()->getUnknown()->hasUnknown());

		$type = $this->resolver([self::PROTECTED => [TypedRenderer::class]])
			->formShapeFor(self::PROTECTED, 'protectedForm', $this->scope());

		self::assertInstanceOf(FormShapeType::class, $type);
		self::assertContains(UnknownReason::UNPROVEN_COMPLETE, $type->getFormShape()->getUnknown()->getReasons());
	}

	public function testNestedExternalMutationHandsOutAPlainObject(): void
	{
		$pairing = new FormPairing(
			$this->storeWith([self::NESTED_MUTATED => [NestedMutatedRenderer::class]]),
			$this->makeResolver(),
		);
		$pairing->bindScope($this->scope());
		$forms = $pairing->formsFor(self::NESTED_MUTATED, 'nestedForm');
		self::assertCount(1, $forms);
		self::assertTrue($forms[0]->isMutatedExternallyAnywhere());

		$type = $this->resolver([self::NESTED_MUTATED => [NestedMutatedRenderer::class]])
			->formShapeFor(self::NESTED_MUTATED, 'nestedForm', $this->scope());

		self::assertInstanceOf(ObjectType::class, $type);
		self::assertNotInstanceOf(FormShapeType::class, $type);
	}

	// The bridge runs only with Forms, Latte and discovery all on; any of them off makes getType() a
	// pass-through, before the helper call or the offset is even looked at.
	public function testDisabledBridgeTypesNothing(): void
	{
		$scope = $this->scope();
		$helperCall = new StaticCall(new FullyQualified(Helpers::class), 'form', [new Arg(new String_('choiceForm'))]);
		$offset = new ArrayDimFetch(new Variable('form'), new String_('years'));

		foreach ([TestGuard::bridge(false), TestGuard::bridge(true, false), TestGuard::bridge(
			true,
			true,
			false,
		)] as $guard) {
			$resolver = $this->resolver([self::TYPING => [TypedRenderer::class]], $guard);

			self::assertNull($resolver->getType($helperCall, $scope));
			self::assertNull($resolver->getType($offset, $scope));
		}
	}

	public function testUnknownTemplateStaysUntyped(): void
	{
		$resolver = $this->resolver([self::TYPING => [TypedRenderer::class]]);

		self::assertNull($resolver->formShapeFor('nope.latte', 'choiceForm', $this->scope()));
		self::assertNull($resolver->controlClassAt('nope.latte', 2, 'years', $this->scope()));
	}

	/**
	 * @param array<string, list<string>> $rendererClassesByTemplate
	 */
	private function resolver(
		array $rendererClassesByTemplate,
		?ConfigurationGuard $guard = null
	): FormMacroTypeResolver
	{
		$universe = new LatteUniverse([self::TEMPLATES], self::TEMPLATES);

		return new FormMacroTypeResolver(
			$guard ?? TestGuard::bridge(),
			new FormMacroCollector($universe, TestAdapter::accessor()),
			new FormPairing($this->storeWith($rendererClassesByTemplate), $this->makeResolver()),
			$universe,
		);
	}

	/**
	 * @param array<string, list<string>> $rendererClassesByTemplate
	 */
	private function storeWith(array $rendererClassesByTemplate): DiscoveryStore
	{
		$store = new DiscoveryStore($this->makeDir());

		$recordsByTemplate = [];
		$classes = [];
		foreach ($rendererClassesByTemplate as $relPath => $classNames) {
			$records = [];
			foreach ($classNames as $className) {
				$records[] = [
					'class' => $className,
					'view' => 'default',
					'kind' => CandidatePath::KIND_CONVENTION,
					'certainty' => Certainty::HAPPENS,
				];
				$classes[$className] = true;
			}

			$recordsByTemplate[$relPath] = $records;
		}

		$store->replaceWith($recordsByTemplate, array_keys($classes), []);

		return $store;
	}

	private function makeResolver(): IndexShapeResolver
	{
		$cache = new FormShapeCache($this->makeDir());
		$index = new RegistrationIndex(
			[self::RENDERERS],
			TestFileFinder::create((string) getcwd()),
			new FileFactIndex($cache, $this->parser(), new RegistrationRecognizer()),
		);

		return new IndexShapeResolver(
			$index,
			$cache,
			$this->parser(),
			self::createReflectionProvider(),
			new ControlAnnotationValueTypeReader(
				self::createReflectionProvider(),
				self::getContainer()->getByType(TypeStringResolver::class),
			),
			[self::RENDERERS],
		);
	}

	private function parser(): Parser
	{
		$parser = self::getContainer()->getService('defaultAnalysisParser');
		assert($parser instanceof Parser);

		return $parser;
	}

	// A REAL analysis scope, borrowed exactly as the pairing test borrows one, rather than the stub
	// the graph rule's test passes: the registered-form walk needs a live scope, and the rule is what
	// binds it in production too - so a stub would test a binding that never happens.

	/**
	 * @return Scope&CollectedDataEmitter&NodeCallbackInvoker
	 */
	private function scope(): Scope
	{
		$captured = null;
		self::processFile(
			self::RENDERERS . '/ClosedRenderer.php',
			static function (Node $node, Scope $scope) use (&$captured): void {
				if ($captured === null && $scope->isInClass() && $scope->getFunctionName() !== null) {
					$captured = $scope;
				}
			},
		);

		self::assertInstanceOf(CollectedDataEmitter::class, $captured);
		self::assertInstanceOf(NodeCallbackInvoker::class, $captured);

		return $captured;
	}

	private function makeDir(): string
	{
		$dir = sys_get_temp_dir() . '/latte-forms-rule-' . uniqid('', true);
		FileSystem::createDir($dir);
		$this->dirs[] = $dir;

		return $dir;
	}

}
