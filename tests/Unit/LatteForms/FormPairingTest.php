<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms;

use Nette\Forms\Container;
use Nette\Forms\Controls\SubmitButton;
use Nette\Forms\Controls\TextInput;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use OriPhpstan\Nette\Forms\Catalog\Stub\ControlAnnotationValueTypeReader;
use OriPhpstan\Nette\Forms\Index\FileFactIndex;
use OriPhpstan\Nette\Forms\Index\IndexShapeResolver;
use OriPhpstan\Nette\Forms\Index\RegistrationIndex;
use OriPhpstan\Nette\Forms\Index\RegistrationRecognizer;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Forms\Shape\ComponentSlot;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use OriPhpstan\Nette\Forms\Shape\ReplicatorShape;
use OriPhpstan\Nette\Forms\Shape\UnknownInfo;
use OriPhpstan\Nette\Forms\Shape\UnknownReason;
use OriPhpstan\Nette\Latte\Bridge\Discovery\CandidatePath;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use OriPhpstan\Nette\LatteForms\ComponentIdentity;
use OriPhpstan\Nette\LatteForms\FormPairing;
use OriPhpstan\Nette\LatteForms\ResolvedForm;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Parser\Parser;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Type\StringType;
use Tests\OriPhpstan\Nette\Toolkit\FormShapeTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestFileFinder;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\AliasReachedRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\ArrayReachedRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\ArrayReadOnlyReachedRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\BareRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\ChainReachedRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\ClosedRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\ConditionalRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\CopyReachedRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\DynamicNameRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\EventReachedRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\ExternallyMutatedRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\FreeReachedRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\GetterReachedRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\HelperReachedRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\InheritedBaseControl;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\InheritedSiblingControl;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\NewsManageRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\NotAFormRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\OpaqueContainerRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\OpenRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\PairingForm;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\ReadOnlyReachedRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\SecondRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\SelfMutatingRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\SubtypedChildControl;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\UnscannedCalleeRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\UntouchedRenderer;
use function array_keys;
use function array_map;
use function assert;
use function getcwd;
use function is_dir;
use function sys_get_temp_dir;
use function uniqid;

final class FormPairingTest extends FormShapeTestCase
{

	private const FIXTURES = __DIR__ . '/Fixtures/Renderer';

	private const TEMPLATE = 'app/LatteFormsFixture/simple.latte';

	private const OTHER_TEMPLATE = 'app/LatteFormsFixture/other.latte';

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

	public function testOneLinkedRendererWhoseComponentResolvesYieldsOneResolvedForm(): void
	{
		$forms = $this->pairingFor([self::TEMPLATE => [ClosedRenderer::class]])
			->formsFor(self::TEMPLATE, 'simpleForm');

		self::assertCount(1, $forms);
		self::assertSame(ClosedRenderer::class, $forms[0]->getRendererClass());
		self::assertSame('simpleForm', $forms[0]->getFormName());
		self::assertSame(PairingForm::class, $forms[0]->getShape()->getClassName());
		self::assertTrue($forms[0]->isClosed());
	}

	public function testThreeLinkedRenderersYieldOnlyTheTwoWhoseComponentResolves(): void
	{
		$forms = $this->pairingFor([
			self::TEMPLATE => [ClosedRenderer::class, SecondRenderer::class, BareRenderer::class],
		])->formsFor(self::TEMPLATE, 'simpleForm');

		self::assertSame(
			[ClosedRenderer::class, SecondRenderer::class],
			array_map(static fn (ResolvedForm $form): string => $form->getRendererClass(), $forms),
		);
	}

	public function testComponentThatIsNotAFormIsExcludedWhileItsFormSiblingSurvives(): void
	{
		$forms = $this->pairingFor([self::TEMPLATE => [NotAFormRenderer::class, ClosedRenderer::class]])
			->formsFor(self::TEMPLATE, 'simpleForm');

		self::assertSame(
			[ClosedRenderer::class],
			array_map(static fn (ResolvedForm $form): string => $form->getRendererClass(), $forms),
			'a plain Nette\Forms\Container component resolves to a shape but is not a form',
		);
	}

	public function testTemplateWithoutDiscoveryRecordsYieldsNothing(): void
	{
		$pairing = $this->pairingFor([self::TEMPLATE => [ClosedRenderer::class]]);

		self::assertCount(1, $pairing->formsFor(self::TEMPLATE, 'simpleForm'));
		self::assertSame([], $pairing->formsFor(self::OTHER_TEMPLATE, 'simpleForm'));
	}

	public function testFormNameThatNoRendererDeclaresYieldsNothing(): void
	{
		$pairing = $this->pairingFor([self::TEMPLATE => [ClosedRenderer::class]]);

		self::assertCount(1, $pairing->formsFor(self::TEMPLATE, 'simpleForm'));
		self::assertSame([], $pairing->formsFor(self::TEMPLATE, 'ghostForm'));
	}

	public function testRepeatedRecordsForOneRendererYieldOneResolvedForm(): void
	{
		$store = $this->storeWith([
			self::TEMPLATE => [
				self::record(ClosedRenderer::class, 'default'),
				self::record(ClosedRenderer::class, 'edit'),
				self::record(ClosedRenderer::class, null),
			],
		]);

		self::assertCount(1, (new FormPairing($store, $this->makeResolver()))->formsFor(self::TEMPLATE, 'simpleForm'));
	}

	public function testShapeCarryingAnUnknownIsResolvedButNotClosed(): void
	{
		$forms = $this->pairingFor([self::TEMPLATE => [OpenRenderer::class]])
			->formsFor(self::TEMPLATE, 'openForm');

		self::assertCount(1, $forms);
		self::assertFalse($forms[0]->isClosed());
		self::assertShape($forms[0]->getShape(), <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\PairingForm{
			  known: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT);
		// The render shows the shape is open but not which reason opened it, and the reason is the
		// point of this test: the dynamic name is what the pairing must refuse to close over.
		self::assertSame(
			[UnknownReason::DYNAMIC_NAME],
			$forms[0]->getShape()->getUnknown()->getReasons(),
		);
		self::assertSame(ResolvedForm::LOOKUP_PRESENT, $forms[0]->lookup([], 'known'));
		self::assertSame(
			ResolvedForm::LOOKUP_UNRESOLVED,
			$forms[0]->lookup([], 'nope'),
			'an open shape can never prove a name absent',
		);
	}

	public function testClosedFormResolvesEveryComponentKindItKnows(): void
	{
		$form = $this->singleForm([ClosedRenderer::class], 'simpleForm');

		self::assertSame(ResolvedForm::LOOKUP_PRESENT, $form->lookup([], 'name'));
		self::assertSame(ResolvedForm::LOOKUP_PRESENT, $form->lookup([], 'email'));
		self::assertSame(
			ResolvedForm::LOOKUP_PRESENT,
			$form->lookup([], 'send'),
			'addSubmit contributes no slot, only a component type - the name still exists',
		);
		self::assertSame(ResolvedForm::LOOKUP_PRESENT, $form->lookup([], 'address'));
		self::assertSame(ResolvedForm::LOOKUP_ABSENT, $form->lookup([], 'nope'));
	}

	public function testDashPathAndMacroNestingComposeIntoTheSameLookup(): void
	{
		$form = $this->singleForm([ClosedRenderer::class], 'deepForm');

		self::assertSame(ResolvedForm::LOOKUP_PRESENT, $form->lookup([], 'outer-inner-deep'));
		self::assertSame(ResolvedForm::LOOKUP_PRESENT, $form->lookup(['outer'], 'inner-deep'));
		self::assertSame(ResolvedForm::LOOKUP_PRESENT, $form->lookup(['outer', 'inner'], 'deep'));
		self::assertSame(ResolvedForm::LOOKUP_ABSENT, $form->lookup(['outer'], 'inner-nope'));
		self::assertSame(ResolvedForm::LOOKUP_ABSENT, $form->lookup([], 'outer-nope'));
		self::assertSame(
			ResolvedForm::LOOKUP_ABSENT,
			$form->lookup(['outer'], 'outer-inner-deep'),
			'the two sources concatenate - the dash path is never absorbed into the macro path',
		);
	}

	public function testHopThroughAKnownNonContainerIsUnresolvedButAnAbsentHopIsAbsent(): void
	{
		$form = $this->singleForm([ClosedRenderer::class], 'simpleForm');

		self::assertSame(
			ResolvedForm::LOOKUP_UNRESOLVED,
			$form->lookup([], 'name-sub'),
			'name exists but is not a container - typing macros against it is out of scope',
		);
		self::assertSame(ResolvedForm::LOOKUP_ABSENT, $form->lookup([], 'nope-sub'));
	}

	public function testMaybePresentSlotIsAKnownNameAndDoesNotOpenTheForm(): void
	{
		$form = $this->singleForm([ConditionalRenderer::class], 'conditionalForm');

		self::assertTrue($form->isClosed(), 'a conditionally added control adds no unknown name');
		self::assertShape($form->getShape(), <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\PairingForm{
			  always: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  maybe?: Nette\Forms\Container{
			    inside: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  },
			  sometimes?: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT, 'the conditional control renders maybe-present and the shape still closes');
		self::assertSame(ResolvedForm::LOOKUP_PRESENT, $form->lookup([], 'sometimes'));
		self::assertSame(ResolvedForm::LOOKUP_ABSENT, $form->lookup([], 'nope'));
	}

	public function testMaybePresentContainerIsNotAClosedPath(): void
	{
		$form = $this->singleForm([ConditionalRenderer::class], 'conditionalForm');

		self::assertShape($form->getShape(), <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\PairingForm{
			  always: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  maybe?: Nette\Forms\Container{
			    inside: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  },
			  sometimes?: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT, 'the conditional container renders maybe-present');
		self::assertSame(ResolvedForm::LOOKUP_PRESENT, $form->lookup([], 'maybe'));
		self::assertSame(ResolvedForm::LOOKUP_UNRESOLVED, $form->lookup(['maybe'], 'inside'));
		self::assertSame(ResolvedForm::LOOKUP_UNRESOLVED, $form->lookup(['maybe'], 'nope'));
	}

	public function testOpenNestedContainerNeverProvesAbsenceInsideItWhileTheRootStillDoes(): void
	{
		$form = $this->singleForm([ConditionalRenderer::class], 'escapedContainerForm');

		self::assertTrue($form->isClosed(), 'the root shape carries no unknown of its own');
		self::assertSame(ResolvedForm::LOOKUP_ABSENT, $form->lookup([], 'nope'));
		self::assertSame(ResolvedForm::LOOKUP_PRESENT, $form->lookup(['ref'], 'known'));
		self::assertSame(
			ResolvedForm::LOOKUP_UNRESOLVED,
			$form->lookup(['ref'], 'nope'),
			'the container carries its own unknown, which the root shape does not report',
		);
	}

	public function testAnOpenRootNeverProvesAbsenceInsideAClosedChild(): void
	{
		$form = $this->singleForm([ConditionalRenderer::class], 'openRootForm');

		self::assertFalse($form->isClosed());
		self::assertShape($form->getShape(), <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\PairingForm{
			  inner: Nette\Forms\Container{
			    known: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  },
			  ...<IComponent>,
			}
			OUTPUT, 'the root carries the unknown and the child container is closed on its own');
		self::assertSame(
			ResolvedForm::LOOKUP_PRESENT,
			$form->lookup(['inner'], 'known'),
			'membership stays trustworthy - a name the shape does list really is there',
		);
		self::assertSame(
			ResolvedForm::LOOKUP_UNRESOLVED,
			$form->lookup(['inner'], 'nope'),
			'an incomplete parent can hide components of any child below it',
		);
	}

	public function testAContainerAttachedAsABuiltComponentIsNeverAClosedPath(): void
	{
		$form = $this->singleForm([OpaqueContainerRenderer::class], 'opaqueForm');

		self::assertTrue($form->isClosed());
		self::assertShape($form->getShape(), <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\PairingForm{
			  opaque: Nette\Forms\Container{},
			  top: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT, 'nothing walked the attached container, so its shape stayed empty and unknown-free');
		self::assertSame(ResolvedForm::LOOKUP_PRESENT, $form->lookup([], 'opaque'));
		self::assertSame(ResolvedForm::LOOKUP_ABSENT, $form->lookup([], 'nope'));
		self::assertSame(
			ResolvedForm::LOOKUP_UNRESOLVED,
			$form->lookup(['opaque'], 'hidden'),
			'an empty container shape is evidence of a container never walked, not of an empty container',
		);
		self::assertSame(ResolvedForm::LOOKUP_UNRESOLVED, $form->lookup(['opaque'], 'nope'));
	}

	public function testAShapeHoldingOnlyOpaqueComponentTypesIsNeverAClosedPath(): void
	{
		$form = $this->singleForm([ClosedRenderer::class], 'buttonBarForm');
		self::assertShape($form->getShape(), <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\PairingForm{
			  bar: Nette\Forms\Container{
			    go: Nette\Forms\Controls\SubmitButton,
			  },
			  top: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT, 'addSubmit contributes an opaque component type only, with no slot beside it');

		self::assertSame(ResolvedForm::LOOKUP_PRESENT, $form->lookup(['bar'], 'go'));
		self::assertSame(
			ResolvedForm::LOOKUP_UNRESOLVED,
			$form->lookup(['bar'], 'nope'),
			'a walk that modelled nothing but opaque names proves nothing about what else is there',
		);
		self::assertSame(ResolvedForm::LOOKUP_ABSENT, $form->lookup([], 'nope'));
	}

	public function testAnEmptyShapeIsNeverClosed(): void
	{
		$form = new ResolvedForm(
			ClosedRenderer::class,
			'form',
			self::shape([], [], [], new UnknownInfo()),
			self::unmutated(),
		);

		self::assertFalse($form->isClosed());
		self::assertSame(ResolvedForm::LOOKUP_UNRESOLVED, $form->lookup([], 'nope'));
	}

	public function testFormMutatedByItsInstantiatorIsNeverAClosedPath(): void
	{
		$form = $this->singleForm([ExternallyMutatedRenderer::class], 'employerForm');

		self::assertFalse(
			$form->isClosed(),
			'OutsideMutator adds user_employer_id to this very form; the builder walk cannot see it',
		);
		self::assertSame(ResolvedForm::LOOKUP_PRESENT, $form->lookup([], 'name'));
		self::assertSame(ResolvedForm::LOOKUP_UNRESOLVED, $form->lookup([], 'user_employer_id'));
		self::assertSame(ResolvedForm::LOOKUP_UNRESOLVED, $form->lookup([], 'nothing_adds_this'));
	}

	public function testFormMutatedTwoOffsetsDeepFromAParentIsNeverAClosedPath(): void
	{
		$form = $this->singleForm([NewsManageRenderer::class], 'newsForm');

		self::assertFalse(
			$form->isClosed(),
			'ParentPresenterReplica adds files_submit through $this[newsManage][newsForm]',
		);
		self::assertSame(ResolvedForm::LOOKUP_PRESENT, $form->lookup([], 'title'));
		self::assertSame(ResolvedForm::LOOKUP_UNRESOLVED, $form->lookup([], 'files_submit'));
	}

	public function testAMutationThatNamesItsOwnerLeavesEveryOtherClassClosed(): void
	{
		$mutated = $this->singleForm([SelfMutatingRenderer::class], 'lateForm');
		$untouched = $this->singleForm([UntouchedRenderer::class], 'lateForm');

		self::assertFalse($mutated->isClosed());
		self::assertSame(ResolvedForm::LOOKUP_UNRESOLVED, $mutated->lookup([], 'nope'));

		self::assertTrue(
			$untouched->isClosed(),
			'the same component NAME on a class no mutation names must not lose its gate',
		);
		self::assertSame(ResolvedForm::LOOKUP_ABSENT, $untouched->lookup([], 'nope'));
	}

	public function testFormReachedThroughAGetterIsNeverAClosedPath(): void
	{
		$reached = $this->singleForm([GetterReachedRenderer::class], 'getterForm');
		$elsewhere = $this->singleForm([ClosedRenderer::class], 'simpleForm');

		self::assertFalse(
			$reached->isClosed(),
			'IndirectMutator reaches this form through $this[getterCtrl]->getForm(), which names no component',
		);
		self::assertSame(ResolvedForm::LOOKUP_PRESENT, $reached->lookup([], 'early'));
		self::assertSame(ResolvedForm::LOOKUP_UNRESOLVED, $reached->lookup([], 'late'));

		self::assertTrue(
			$elsewhere->isClosed(),
			'an unnameable mutated component opens the class that OWNS it, never every class',
		);
		self::assertSame(ResolvedForm::LOOKUP_ABSENT, $elsewhere->lookup([], 'nope'));
	}

	public function testFormReachedThroughAChainOfLocalCopiesIsNeverAClosedPath(): void
	{
		$reached = $this->singleForm([AliasReachedRenderer::class], 'aliasForm');

		self::assertFalse(
			$reached->isClosed(),
			'IndirectMutator copies the access into one local and that local into another',
		);
		self::assertSame(ResolvedForm::LOOKUP_PRESENT, $reached->lookup([], 'early'));
		self::assertSame(ResolvedForm::LOOKUP_UNRESOLVED, $reached->lookup([], 'late'));
	}

	public function testFormHandedToAHelperIsNeverAClosedPath(): void
	{
		$direct = $this->singleForm([HelperReachedRenderer::class], 'helperForm');
		$chained = $this->singleForm([ChainReachedRenderer::class], 'chainForm');
		$readOnly = $this->singleForm([ReadOnlyReachedRenderer::class], 'readOnlyForm');

		self::assertFalse(
			$direct->isClosed(),
			'IndirectMutator passes this form to a method whose body no builder walk reaches',
		);
		self::assertSame(ResolvedForm::LOOKUP_PRESENT, $direct->lookup([], 'early'));
		self::assertSame(ResolvedForm::LOOKUP_UNRESOLVED, $direct->lookup([], 'late'));

		self::assertFalse(
			$chained->isClosed(),
			'the registering helper is one hand-over further away, which the callee closure reaches',
		);
		self::assertSame(ResolvedForm::LOOKUP_UNRESOLVED, $chained->lookup([], 'late'));

		self::assertTrue(
			$readOnly->isClosed(),
			'a hand-over to a method that only READS the form registers nothing and proves nothing',
		);
		self::assertSame(ResolvedForm::LOOKUP_ABSENT, $readOnly->lookup([], 'late'));
	}

	public function testFormHandedOverInsideALiteralArrayIsNeverAClosedPath(): void
	{
		$reached = $this->singleForm([ArrayReachedRenderer::class], 'arrayForm');
		$readOnly = $this->singleForm([ArrayReadOnlyReachedRenderer::class], 'arrayReadOnlyForm');

		self::assertFalse(
			$reached->isClosed(),
			'IndirectMutator passes this form inside a literal array to a callee that registers on the elements',
		);
		self::assertSame(ResolvedForm::LOOKUP_PRESENT, $reached->lookup([], 'early'));
		self::assertSame(ResolvedForm::LOOKUP_UNRESOLVED, $reached->lookup([], 'late'));

		self::assertTrue(
			$readOnly->isClosed(),
			'the array unwrap stays conditional on the callee - a wrapper is not a mutation by itself',
		);
		self::assertSame(ResolvedForm::LOOKUP_ABSENT, $readOnly->lookup([], 'late'));
	}

	public function testFormHandedToAnUnscannedCalleeKeepsItsGate(): void
	{
		$unscanned = $this->singleForm([UnscannedCalleeRenderer::class], 'unscannedForm');

		self::assertTrue(
			$unscanned->isClosed(),
			'a callee outside the folded universe (here Latte\Engine::addProvider, the corpus\'s own '
				. 'formsStack spelling) leaves the hand-over undecided, and undecided keeps the gate',
		);
		self::assertSame(ResolvedForm::LOOKUP_ABSENT, $unscanned->lookup([], 'late'));
	}

	public function testFormMutatedThroughACopyOfTheCalleesParameterIsNeverAClosedPath(): void
	{
		$reached = $this->singleForm([CopyReachedRenderer::class], 'copyForm');

		self::assertFalse(
			$reached->isClosed(),
			'the callee copies its parameter into a local before registering, which the parameter side must follow',
		);
		self::assertSame(ResolvedForm::LOOKUP_PRESENT, $reached->lookup([], 'early'));
		self::assertSame(ResolvedForm::LOOKUP_UNRESOLVED, $reached->lookup([], 'late'));
	}

	public function testFormHandedToAFreeFunctionIsNeverAClosedPath(): void
	{
		$reached = $this->singleForm([FreeReachedRenderer::class], 'freeForm');

		self::assertFalse(
			$reached->isClosed(),
			'decorateFreeForm() is declared in the analysed universe, so its body decides the hand-over',
		);
		self::assertSame(ResolvedForm::LOOKUP_PRESENT, $reached->lookup([], 'early'));
		self::assertSame(ResolvedForm::LOOKUP_UNRESOLVED, $reached->lookup([], 'late'));
	}

	public function testFormDispatchedThroughAnEventPropertyIsNeverAClosedPath(): void
	{
		$reached = $this->singleForm([EventReachedRenderer::class], 'eventForm');

		self::assertFalse(
			$reached->isClosed(),
			'$this->onReach() invokes a PROPERTY holding subscriber callables, so no callee body exists to decide it',
		);
		self::assertSame(ResolvedForm::LOOKUP_PRESENT, $reached->lookup([], 'early'));
		self::assertSame(ResolvedForm::LOOKUP_UNRESOLVED, $reached->lookup([], 'late'));
	}

	public function testMutationKeyedToADeclaredBaseTypeReachesTheSubclassHoldingTheComponent(): void
	{
		$reached = $this->singleForm([SubtypedChildControl::class], 'subtypedForm');

		self::assertFalse(
			$reached->isClosed(),
			'the owner the index can name is the builder\'s declared SubtypedBaseControl, not this class',
		);
		self::assertSame(ResolvedForm::LOOKUP_PRESENT, $reached->lookup([], 'early'));
		self::assertSame(ResolvedForm::LOOKUP_UNRESOLVED, $reached->lookup([], 'late'));
	}

	public function testMutationKeyedToASubclassReachesTheBaseDeclaringTheComponent(): void
	{
		$base = $this->singleForm([InheritedBaseControl::class], 'inheritedForm');
		$sibling = $this->singleForm([InheritedSiblingControl::class], 'inheritedForm');

		self::assertFalse(
			$base->isClosed(),
			'the owner the index can name is InheritedChildControl, a subclass of the class holding the component',
		);
		self::assertSame(ResolvedForm::LOOKUP_PRESENT, $base->lookup([], 'early'));
		self::assertSame(ResolvedForm::LOOKUP_UNRESOLVED, $base->lookup([], 'late'));

		self::assertTrue(
			$sibling->isClosed(),
			'no instance of the mutated subclass is a sibling of it - the match reaches up and down, never across',
		);
		self::assertSame(ResolvedForm::LOOKUP_ABSENT, $sibling->lookup([], 'late'));
	}

	public function testADynamicallyNamedButtonOpensTheShapeThoughItCarriesNoValueUnknown(): void
	{
		$form = $this->singleForm([DynamicNameRenderer::class], 'dynamicForm');

		self::assertShape($form->getShape(), <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\PairingForm{
			  known: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT, 'addSubmit($dynamic) contributes no value, so the value axis stays silent');
		// ComponentShapeRenderer shows only the VALUE axis of UnknownInfo - a name-axis-only unknown
		// renders as a closed shape - and the name axis is the whole point of this test, so it keeps
		// its own assertion beside the snapshot.
		self::assertSame([UnknownReason::DYNAMIC_NAME], $form->getShape()->getUnknown()->getNameReasons());
		self::assertFalse($form->isClosed());
		self::assertSame(ResolvedForm::LOOKUP_PRESENT, $form->lookup([], 'known'));
		self::assertSame(ResolvedForm::LOOKUP_UNRESOLVED, $form->lookup([], 'save'));
	}

	public function testEachLinkedRendererIsPairedIndependently(): void
	{
		$forms = $this->pairingFor([self::TEMPLATE => [ClosedRenderer::class, SecondRenderer::class]])
			->formsFor(self::TEMPLATE, 'simpleForm');

		self::assertCount(2, $forms);
		self::assertSame(ResolvedForm::LOOKUP_PRESENT, $forms[0]->lookup([], 'email'));
		self::assertSame(ResolvedForm::LOOKUP_ABSENT, $forms[1]->lookup([], 'email'));
		self::assertSame(ResolvedForm::LOOKUP_PRESENT, $forms[0]->lookup([], 'name'));
		self::assertSame(ResolvedForm::LOOKUP_PRESENT, $forms[1]->lookup([], 'name'));
	}

	public function testAnyUnknownReasonWhatsoeverOpensTheShape(): void
	{
		foreach ([UnknownReason::UNFOLLOWED_CALL, UnknownReason::EXTENSION_METHOD, UnknownReason::VENDOR_MAGIC_CALL] as $reason) {
			$form = new ResolvedForm(
				ClosedRenderer::class,
				'form',
				self::shape(['field' => Certainty::HAPPENS], [], [], new UnknownInfo([$reason])),
				self::unmutated(),
			);

			self::assertFalse($form->isClosed(), $reason . ' must open the shape');
			self::assertSame(ResolvedForm::LOOKUP_UNRESOLVED, $form->lookup([], 'nope'));
			self::assertSame(ResolvedForm::LOOKUP_PRESENT, $form->lookup([], 'field'));
		}
	}

	public function testNonDefiniteSlotPresenceAloneNeverOpensTheShape(): void
	{
		foreach ([Certainty::MAYBE, Certainty::UNKNOWN, Certainty::NEVER] as $presence) {
			$form = new ResolvedForm(
				ClosedRenderer::class,
				'form',
				self::shape(['field' => $presence], [], [], new UnknownInfo()),
				self::unmutated(),
			);

			self::assertTrue($form->isClosed(), $presence . ' presence keeps the name set complete');
			self::assertSame(ResolvedForm::LOOKUP_PRESENT, $form->lookup([], 'field'));
			self::assertSame(ResolvedForm::LOOKUP_ABSENT, $form->lookup([], 'nope'));
		}
	}

	public function testReplicatorNameIsPresentAndItsInteriorIsUnresolved(): void
	{
		$inner = self::shape(['line' => Certainty::HAPPENS], [], [], new UnknownInfo());
		$form = new ResolvedForm(
			ClosedRenderer::class,
			'form',
			self::shape([], [], ['rows' => new ReplicatorShape($inner, FormShape::empty())], new UnknownInfo()),
			self::unmutated(),
		);

		self::assertSame(ResolvedForm::LOOKUP_PRESENT, $form->lookup([], 'rows'));
		self::assertSame(
			ResolvedForm::LOOKUP_UNRESOLVED,
			$form->lookup(['rows'], 'line'),
			'a replicator is indexed by replica number at runtime, which no static path expresses',
		);
		self::assertSame(ResolvedForm::LOOKUP_UNRESOLVED, $form->lookup(['rows'], 'nope'));
		self::assertSame(ResolvedForm::LOOKUP_ABSENT, $form->lookup([], 'nope'));
	}

	// The measured false positive this shape resolution exists to prevent, and the two halves of why
	// it happens. A builder that hands its form to a helper which attaches a replicator has exactly
	// two possible shapes: with the replicator (the helper was followed) or without it (it was not).
	// Closing the second one reports every name under the replicator as absent - the two
	// `question_filter.addNode` findings the corpus produced when the certainty gate was loosened
	// without following the call. Closing the FIRST cannot: the segment is a known name, and a
	// replicator's interior is indexed by replica number, so the hop is unresolvable by construction.
	// So the two are alternatives, not a spectrum - either the helper is followed and the name set
	// really is complete, or it is not followed and the shape stays open.
	public function testAFollowedReplicatorMakesTheNameUnderItUnresolvableRatherThanAbsent(): void
	{
		$inner = self::shape(['value' => Certainty::HAPPENS], [], [], new UnknownInfo());
		$followed = new ResolvedForm(
			ClosedRenderer::class,
			'form',
			self::shape(
				['name' => Certainty::HAPPENS],
				[],
				['question_filter' => new ReplicatorShape($inner, FormShape::empty())],
				new UnknownInfo(),
			),
			self::unmutated(),
		);

		self::assertTrue($followed->isClosed());
		self::assertSame(
			ResolvedForm::LOOKUP_UNRESOLVED,
			$followed->lookup([], 'question_filter-addNode'),
			'a known replicator hop can never prove what is or is not attached below it',
		);

		$unfollowed = new ResolvedForm(
			ClosedRenderer::class,
			'form',
			self::shape(['name' => Certainty::HAPPENS], [], [], new UnknownInfo()),
			self::unmutated(),
		);

		self::assertSame(
			ResolvedForm::LOOKUP_ABSENT,
			$unfollowed->lookup([], 'question_filter-addNode'),
			'closing a shape the helper never contributed to is what manufactured the false positive',
		);
	}

	// identify() reads the CHANNEL the shape recorded a component in, not any declared type: a slot
	// is a control, a container is a container, and an omitted-value component (addSubmit) is a
	// control the shape carries no slot for at all - which is why the component-type channel has to
	// be consulted as well, and why `send` here has a class while nothing else records one for it.
	public function testIdentifyAnswersFromTheChannelTheShapeRecordedEachComponentIn(): void
	{
		$form = $this->singleForm([ClosedRenderer::class], 'simpleForm');

		self::assertSame(ComponentIdentity::KIND_CONTROL, self::identity($form, [], 'name')->getKind());
		self::assertSame([TextInput::class], self::identity($form, [], 'name')->getClasses());
		self::assertSame(ComponentIdentity::KIND_CONTROL, self::identity($form, [], 'send')->getKind());
		self::assertSame([SubmitButton::class], self::identity($form, [], 'send')->getClasses());
		self::assertSame(ComponentIdentity::KIND_CONTAINER, self::identity($form, [], 'address')->getKind());
		self::assertSame([Container::class], self::identity($form, [], 'address')->getClasses());

		self::assertNull(
			$form->identify([], 'nope'),
			'a name the shape does not list is lookup()\'s question, not this one',
		);
	}

	// The two spellings of one nested path compose here exactly as they do for lookup(), and a hop
	// that is not a traversable container answers nothing rather than guessing.
	public function testIdentifyComposesBothSpellingsOfANestedPath(): void
	{
		$form = $this->singleForm([ClosedRenderer::class], 'simpleForm');

		self::assertSame(ComponentIdentity::KIND_CONTROL, self::identity($form, ['address'], 'street')->getKind());
		self::assertSame(ComponentIdentity::KIND_CONTROL, self::identity($form, [], 'address-street')->getKind());
		self::assertNull($form->identify(['name'], 'street'), 'a control is not a hop');
		self::assertNull($form->identify(['address'], 'nope'));
	}

	// A slot whose VALUE the analyser could not type still knows which control it is: the class lands
	// in the component-type channel and the slot's own class list stays null, so reading only the
	// slot would throw away the one piece of evidence the type checks need.
	public function testATypeOpaqueSlotTakesItsClassFromTheComponentTypeChannel(): void
	{
		$form = new ResolvedForm(
			ClosedRenderer::class,
			'form',
			new FormShape(
				PairingForm::class,
				['opaque' => new ComponentSlot(
					'opaque',
					new StringType(),
					Certainty::HAPPENS,
					[],
					null,
					false,
					null,
					false,
					true,
				)],
				[],
				[],
				new UnknownInfo(),
				[],
				[],
				[],
				['opaque' => SubmitButton::class],
			),
			self::unmutated(),
		);

		self::assertSame(ComponentIdentity::KIND_CONTROL, self::identity($form, [], 'opaque')->getKind());
		self::assertSame([SubmitButton::class], self::identity($form, [], 'opaque')->getClasses());
	}

	// A replicator is a Nette\Forms\Container at runtime and the macros cannot tell it from one, so
	// it answers CONTAINER - with the class the component-type channel carries, which is the only
	// place a replicator's own class is ever recorded.
	public function testAReplicatorIdentifiesAsAContainer(): void
	{
		$form = new ResolvedForm(
			ClosedRenderer::class,
			'form',
			new FormShape(
				PairingForm::class,
				[],
				[],
				['rows' => new ReplicatorShape(
					self::shape(['line' => Certainty::HAPPENS], [], [], new UnknownInfo()),
					FormShape::empty(),
				)],
				new UnknownInfo(),
				[],
				[],
				[],
				['rows' => Container::class],
			),
			self::unmutated(),
		);

		self::assertSame(ComponentIdentity::KIND_CONTAINER, self::identity($form, [], 'rows')->getKind());
		self::assertSame([Container::class], self::identity($form, [], 'rows')->getClasses());
	}

	// The one shape in which a name's kind is genuinely unknowable: two branches attached it as
	// different things, so both channels carry it and neither is the answer.
	public function testANameTwoBranchesAttachedDifferentlyHasNoIdentity(): void
	{
		$form = new ResolvedForm(
			ClosedRenderer::class,
			'form',
			self::shape(
				['both' => Certainty::MAYBE],
				['both' => [
					self::shape(['inside' => Certainty::HAPPENS], [], [], new UnknownInfo()),
					Certainty::HAPPENS,
				]],
				[],
				new UnknownInfo(),
			),
			self::unmutated(),
		);

		self::assertNull($form->identify([], 'both'));
		self::assertNull(
			$form->identify(['both'], 'inside'),
			'and it is not a hop either - the branch that attached a control leaves nothing to descend into',
		);
	}

	// A hop through a container that is not definitely attached is not a path, so nothing below it
	// has an identity - while the container itself still has one, because presence and kind are
	// independent axes.
	public function testAMaybeAttachedContainerIsStillAContainerButIsNotAHop(): void
	{
		$form = new ResolvedForm(
			ClosedRenderer::class,
			'form',
			self::shape(
				[],
				['maybe' => [self::shape(
					['inside' => Certainty::HAPPENS],
					[],
					[],
					new UnknownInfo(),
				), Certainty::MAYBE]],
				[],
				new UnknownInfo(),
			),
			self::unmutated(),
		);

		self::assertSame(ComponentIdentity::KIND_CONTAINER, self::identity($form, [], 'maybe')->getKind());
		self::assertNull($form->identify(['maybe'], 'inside'));
	}

	// THE GATE, and the whole reason this check reaches further than the absence one. Every reason
	// the Forms extension calls a LOST-FIELD reason can make a recorded name mean something else, so
	// it declines; every other reason records something the walk could not ADD, and an add can never
	// rebind a name Container::addComponent() already holds.
	public function testTheIdentityGateDeclinesOnLostFieldReasonsAndOnNoOthers(): void
	{
		$lost = [...UnknownReason::LOST_FIELD_UNKNOWN_REASONS, UnknownReason::UNRESOLVED_ORIGIN];
		foreach ($lost as $reason) {
			self::assertNull(
				self::formWithReason($reason)->identify([], 'field'),
				$reason . ' may have taken the component away, so what the shape says it IS no longer holds',
			);
		}

		$kept = [
			UnknownReason::DYNAMIC_NAME,
			UnknownReason::UNFOLLOWED_CALL,
			UnknownReason::EXTENSION_METHOD,
			UnknownReason::VENDOR_MAGIC_CALL,
			UnknownReason::NON_ENUMERABLE_CLOSURE,
		];
		foreach ($kept as $reason) {
			$form = self::formWithReason($reason);

			self::assertFalse($form->isClosed(), $reason . ' still opens the shape for the absence check');
			self::assertSame(
				ComponentIdentity::KIND_CONTROL,
				self::identity($form, [], 'field')->getKind(),
				$reason . ' records an unenumerated ADD, which cannot rebind a name the walk did read',
			);
		}
	}

	// The other input to the same gate: a component reachable from outside its builder may be
	// removed and re-added as anything, and no unknown reason on the shape records that.
	public function testAnExternallyMutatedComponentHasNoIdentity(): void
	{
		$form = new ResolvedForm(
			ClosedRenderer::class,
			'form',
			self::shape(['field' => Certainty::HAPPENS], [], [], new UnknownInfo()),
			static fn (?string $ownerClass, string $componentName): bool => true,
		);

		self::assertNull($form->identify([], 'field'));
	}

	private static function formWithReason(string $reason): ResolvedForm
	{
		return new ResolvedForm(
			ClosedRenderer::class,
			'form',
			self::shape(['field' => Certainty::HAPPENS], [], [], new UnknownInfo([$reason])),
			self::unmutated(),
		);
	}

	/**
	 * @param list<string> $containerPath
	 */
	private static function identity(ResolvedForm $form, array $containerPath, string $name): ComponentIdentity
	{
		$identity = $form->identify($containerPath, $name);
		self::assertInstanceOf(ComponentIdentity::class, $identity);

		return $identity;
	}

	/**
	 * The oracle a hand-built shape is judged under: these rows pin the shape half of the gate, so
	 * they state outright that no external mutation is in play.
	 *
	 * @return callable(string|null, string): bool
	 */
	private static function unmutated(): callable
	{
		return static fn (?string $ownerClass, string $componentName): bool => false;
	}

	/**
	 * @param array<string, string> $slotPresence
	 * @param array<string, array{FormShape, string}> $containers
	 * @param array<string, ReplicatorShape> $replicators
	 */
	private static function shape(
		array $slotPresence,
		array $containers,
		array $replicators,
		UnknownInfo $unknown
	): FormShape
	{
		$slots = [];
		foreach ($slotPresence as $name => $presence) {
			$slots[$name] = new ComponentSlot($name, new StringType(), $presence, []);
		}

		$childShapes = [];
		$childPresence = [];
		foreach ($containers as $name => [$child, $presence]) {
			$childShapes[$name] = $child;
			$childPresence[$name] = $presence;
		}

		return new FormShape(
			PairingForm::class,
			$slots,
			$childShapes,
			$replicators,
			$unknown,
			[],
			$childPresence,
		);
	}

	/**
	 * @param list<string> $rendererClasses
	 */
	private function singleForm(array $rendererClasses, string $formName): ResolvedForm
	{
		$forms = $this->pairingFor([self::TEMPLATE => $rendererClasses])->formsFor(self::TEMPLATE, $formName);

		self::assertCount(1, $forms);

		return $forms[0];
	}

	/**
	 * @param array<string, list<string>> $rendererClassesByTemplate
	 */
	private function pairingFor(array $rendererClassesByTemplate): FormPairing
	{
		$recordsByTemplate = [];
		foreach ($rendererClassesByTemplate as $relPath => $classNames) {
			$records = [];
			foreach ($classNames as $className) {
				$records[] = self::record($className, 'default');
			}

			$recordsByTemplate[$relPath] = $records;
		}

		return new FormPairing($this->storeWith($recordsByTemplate), $this->makeResolver());
	}

	/**
	 * @return array{class: string, view: string|null, kind: string, certainty: string}
	 */
	private static function record(string $className, ?string $view): array
	{
		return [
			'class' => $className,
			'view' => $view,
			'kind' => CandidatePath::KIND_CONVENTION,
			'certainty' => Certainty::HAPPENS,
		];
	}

	/**
	 * @param array<string, list<array{class: string, view: string|null, kind: string, certainty: string}>> $recordsByTemplate
	 */
	private function storeWith(array $recordsByTemplate): DiscoveryStore
	{
		$store = new DiscoveryStore($this->makeDir());

		$classes = [];
		foreach ($recordsByTemplate as $records) {
			foreach ($records as $record) {
				$classes[$record['class']] = true;
			}
		}

		$store->replaceWith($recordsByTemplate, array_keys($classes), []);

		return $store;
	}

	private function makeResolver(): IndexShapeResolver
	{
		$cache = new FormShapeCache($this->makeDir());
		$index = new RegistrationIndex(
			[self::FIXTURES],
			TestFileFinder::create((string) getcwd()),
			new FileFactIndex($cache, $this->parser(), new RegistrationRecognizer()),
		);

		$resolver = new IndexShapeResolver(
			$index,
			$cache,
			$this->parser(),
			self::createReflectionProvider(),
			new ControlAnnotationValueTypeReader(
				self::createReflectionProvider(),
				self::getContainer()->getByType(TypeStringResolver::class),
			),
			[self::FIXTURES],
		);
		$resolver->bindScope($this->scope());

		return $resolver;
	}

	private function parser(): Parser
	{
		$parser = self::getContainer()->getService('defaultAnalysisParser');
		assert($parser instanceof Parser);

		return $parser;
	}

	private function scope(): Scope
	{
		$captured = null;
		self::processFile(
			self::FIXTURES . '/ClosedRenderer.php',
			static function (Node $node, Scope $scope) use (&$captured): void {
				if ($captured === null && $scope->isInClass() && $scope->getFunctionName() !== null) {
					$captured = $scope;
				}
			},
		);

		self::assertNotNull($captured);

		return $captured;
	}

	private function makeDir(): string
	{
		$dir = sys_get_temp_dir() . '/latte-forms-pairing-' . uniqid('', true);
		FileSystem::createDir($dir);
		$this->dirs[] = $dir;

		return $dir;
	}

}
