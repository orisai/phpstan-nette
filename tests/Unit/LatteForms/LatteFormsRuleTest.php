<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms;

use Nette\Forms\Controls\SubmitButton;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use OriPhpstan\Nette\Forms\Catalog\Stub\ControlAnnotationValueTypeReader;
use OriPhpstan\Nette\Forms\Index\FileFactIndex;
use OriPhpstan\Nette\Forms\Index\IndexShapeResolver;
use OriPhpstan\Nette\Forms\Index\RegistrationIndex;
use OriPhpstan\Nette\Forms\Index\RegistrationRecognizer;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Latte\Bridge\Discovery\CandidatePath;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Rule\LatteAnalyzedFileMarkerCollector;
use OriPhpstan\Nette\LatteForms\FormMacroCollector;
use OriPhpstan\Nette\LatteForms\FormPairing;
use OriPhpstan\Nette\LatteForms\LatteFormsRule;
use OriPhpstan\Nette\LatteForms\MacroSuitability;
use PhpParser\Node;
use PHPStan\Analyser\CollectedDataEmitter;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Parser\Parser;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Rules\FileRuleError;
use PHPStan\Rules\FixableNodeRuleError;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\LineRuleError;
use PHPStan\Rules\NonIgnorableRuleError;
use Tests\OriPhpstan\Nette\Toolkit\FormShapeTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;
use Tests\OriPhpstan\Nette\Toolkit\TestFileFinder;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\BareRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\ClosedRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\ConditionalRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\LostFieldRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\NonFormRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\NotAFormRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\OpenSimpleRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\OverridingRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\SecondRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\SharedPartialRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\TypeAgreeingRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\TypeDivergentRenderer;
use Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer\TypeMutatedRenderer;
use function array_keys;
use function array_map;
use function assert;
use function getcwd;
use function is_dir;
use function sys_get_temp_dir;
use function uniqid;

// The diagnostics themselves. Two properties carry the whole file: every reported row is a fixture
// that stops being reported the moment the rule's decision is broken, and every SILENT row asserts
// its silence NEXT TO a reported row produced by the same processNode() call - so a rule that went
// quiet fails on the positive half first. The corpus delta of this feature is zero by design, which
// makes "no findings" worthless as evidence and these fixtures the only evidence there is.
final class LatteFormsRuleTest extends FormShapeTestCase
{

	private const RENDERERS = __DIR__ . '/Fixtures/Renderer';

	private const TEMPLATES = __DIR__ . '/Fixtures/Rule';

	private const ABSENT = 'absent.latte';

	private const CONTAINER = 'container.latte';

	private const DYNAMIC = 'dynamic.latte';

	private const DYNAMIC_FORM = 'dynamic-form.latte';

	private const GUARDED = 'guarded.latte';

	private const OTHER = 'other.latte';

	private const TYPES = 'types.latte';

	private const TYPES_NLABEL = 'types-nlabel.latte';

	private const TYPES_OPEN = 'types-open.latte';

	private const TYPES_LOST = 'types-lost.latte';

	private const TYPES_MUTATED = 'types-mutated.latte';

	private const TYPES_CONDITIONAL = 'types-conditional.latte';

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

	public function testAbsentControlIsReportedOnItsOwnTemplateLine(): void
	{
		$errors = $this->reported([self::ABSENT => [ClosedRenderer::class]], [self::ABSENT]);

		self::assertCount(1, $errors);
		self::assertSame(LatteFormsRule::UNKNOWN_CONTROL_IDENTIFIER, $errors[0]->getIdentifier());
		self::assertSame(
			"Control 'nope' does not exist on form 'simpleForm' ("
			. ClosedRenderer::class
			. ').',
			$errors[0]->getMessage(),
		);
		self::assertInstanceOf(FileRuleError::class, $errors[0]);
		self::assertSame(self::TEMPLATES . '/' . self::ABSENT, $errors[0]->getFile());
		self::assertSame([3], $this->lines($errors));
	}

	// The reported row above and this silent one come out of the SAME call: `name` is present on the
	// form, so a rule that reported everything would fail here and a rule that reported nothing
	// would fail above.
	public function testPresentControlIsSilentAlongsideItsAbsentSibling(): void
	{
		$errors = $this->reported([self::ABSENT => [ClosedRenderer::class]], [self::ABSENT]);

		self::assertSame(
			["Control 'nope' does not exist on form 'simpleForm' (" . ClosedRenderer::class . ').'],
			$this->messages($errors),
		);
	}

	// Both sources of nesting render as one dotted path, and both spellings of the same runtime
	// lookup produce the same text: the macro chain ({formContainer address} + {input nope}) and the
	// '-' path ({input address-nope}) are the same component.
	public function testContainerPathsRenderDottedForBothSpellings(): void
	{
		$errors = $this->reported([self::CONTAINER => [ClosedRenderer::class]], [self::CONTAINER]);

		self::assertSame(
			[
				"Control 'address.nope' does not exist on form 'simpleForm' (" . ClosedRenderer::class . ').',
				"Control 'address.nope' does not exist on form 'simpleForm' (" . ClosedRenderer::class . ').',
			],
			$this->messages($errors),
		);
		self::assertSame([4, 6], $this->lines($errors));
	}

	// The multi-renderer rule, positive direction: absent from EVERY resolved form, so the message
	// names every renderer it is absent from.
	public function testNameAbsentFromEveryRendererIsReportedNamingAllOfThem(): void
	{
		$errors = $this->reported(
			[self::ABSENT => [ClosedRenderer::class, SecondRenderer::class]],
			[self::ABSENT],
		);

		self::assertSame(
			[
				"Control 'nope' does not exist on form 'simpleForm' ("
				. ClosedRenderer::class . ', ' . SecondRenderer::class . ').',
			],
			$this->messages($errors),
		);
	}

	// The renderer list is a DOCUMENTED promise ("sorted and comma-separated", docs/phpstan-latte-forms.md),
	// so the order the links were recorded in must never reach the message. Same corpus as the row
	// above, links recorded the other way round. Two mechanisms deliver it today - the store
	// canonicalises its records by class and the rule sorts what it prints - and this row pins the
	// promise rather than either one, so removing the last of them is what fails.
	public function testTheRendererListIsSortedWhateverOrderTheLinksWereRecordedIn(): void
	{
		$errors = $this->reported(
			[self::ABSENT => [SecondRenderer::class, ClosedRenderer::class]],
			[self::ABSENT],
		);

		self::assertSame(
			[
				"Control 'nope' does not exist on form 'simpleForm' ("
				. ClosedRenderer::class . ', ' . SecondRenderer::class . ').',
			],
			$this->messages($errors),
		);
	}

	// The multi-renderer rule, negative direction: present in ONE of three linked renderers is the
	// shared-partial pattern the analysis cannot tell from a bug, so it stays silent. Detection
	// power comes from the row above, which differs only in the third renderer.
	public function testNamePresentInOneOfThreeRenderersSilencesTheOtherTwo(): void
	{
		$errors = $this->reported(
			[self::ABSENT => [ClosedRenderer::class, SecondRenderer::class, SharedPartialRenderer::class]],
			[self::ABSENT],
		);

		self::assertSame([], $this->messages($errors));
	}

	// THE PIN (design §2, review §11.7): an UNRESOLVED renderer must PREVENT the report, never be
	// skipped as "not present". The two runs differ by exactly one linked renderer whose form the
	// analyser cannot enumerate - reporting "absent from the resolved subset" while that renderer
	// may well declare the name is the false-positive shape this feature spent four review rounds
	// removing.
	public function testAnUnresolvedRendererBlocksAReportTheClosedOneWouldMake(): void
	{
		self::assertCount(
			1,
			$this->reported([self::ABSENT => [ClosedRenderer::class]], [self::ABSENT]),
			'the closed renderer alone reports',
		);

		self::assertSame(
			[],
			$this->messages($this->reported(
				[self::ABSENT => [ClosedRenderer::class, OpenSimpleRenderer::class]],
				[self::ABSENT],
			)),
			'one open renderer alongside it must silence the finding entirely',
		);
	}

	// THE SAME PIN ONE LEVEL UP. A renderer whose COMPONENT does not resolve never becomes a form at
	// all, so it never reaches lookup() and cannot answer UNRESOLVED there - and the resolved subset
	// would be reported as if it were the whole truth. Both shapes of that evidence gap (no such
	// component, and a component the reflector cannot place) must cancel the report the closed
	// renderer alone makes.
	public function testARendererWhoseComponentDoesNotResolveBlocksTheControlReport(): void
	{
		self::assertCount(
			1,
			$this->reported([self::ABSENT => [ClosedRenderer::class]], [self::ABSENT]),
			'the closed renderer alone reports',
		);

		self::assertSame(
			[],
			$this->messages($this->reported(
				[self::ABSENT => [ClosedRenderer::class, BareRenderer::class]],
				[self::ABSENT],
			)),
			'a renderer declaring no such component may still be the one that renders this template',
		);

		self::assertSame(
			[],
			$this->messages($this->reported(
				[self::ABSENT => [ClosedRenderer::class, NotAFormRenderer::class]],
				[self::ABSENT],
			)),
			'a component the analyser cannot prove is a form leaves the same gap',
		);
	}

	// The certainty gate with no closed renderer at all: an open shape lists a name set the walk
	// could not finish, so nothing about it is negative evidence.
	public function testAnOpenRendererAloneNeverReports(): void
	{
		self::assertSame(
			[],
			$this->messages($this->reported([self::ABSENT => [OpenSimpleRenderer::class]], [self::ABSENT])),
		);
	}

	public function testDynamicReferenceIsSkippedWhileItsLiteralSiblingIsReported(): void
	{
		$errors = $this->reported([self::DYNAMIC => [ClosedRenderer::class]], [self::DYNAMIC]);

		self::assertSame(
			["Control 'nope' does not exist on form 'simpleForm' (" . ClosedRenderer::class . ').'],
			$this->messages($errors),
		);
		self::assertSame([3], $this->lines($errors));
	}

	// A {form $var} scope names no component, so nothing inside it can be resolved - while the
	// literal sibling scope in the same template still is.
	public function testDynamicFormScopeIsSkippedWhileItsLiteralSiblingIsReported(): void
	{
		$errors = $this->reported([self::DYNAMIC_FORM => [ClosedRenderer::class]], [self::DYNAMIC_FORM]);

		self::assertSame(
			["Control 'nope' does not exist on form 'simpleForm' (" . ClosedRenderer::class . ').'],
			$this->messages($errors),
		);
		self::assertSame([5], $this->lines($errors));
	}

	// The nameless-site skip is LOAD-BEARING, not a typing guard: an empty component name resolves to
	// Nette's own createComponent() hook through 'createComponent' . ucfirst(''), so a renderer that
	// overrides it with a concrete return type answers a real shape for the empty name. Without the
	// skip the dynamic scope below is proven to render no form under the name ''. Asserted next to a
	// reported row from the same call.
	public function testDynamicFormScopeIsNeverResolvedAgainstAnOverriddenCreateComponent(): void
	{
		$errors = $this->reported(
			[
				self::DYNAMIC_FORM => [OverridingRenderer::class],
				self::ABSENT => [ClosedRenderer::class],
			],
			[self::DYNAMIC_FORM, self::ABSENT],
		);

		self::assertSame(
			["Control 'nope' does not exist on form 'simpleForm' (" . ClosedRenderer::class . ').'],
			$this->messages($errors),
		);
	}

	// Task 1 records the n:ifset guard; this rule honours it. The guarded and the unguarded
	// reference name components that are equally absent, so only the guard can explain the
	// difference.
	public function testGuardedReferenceIsNotReportedWhileItsUnguardedSiblingIs(): void
	{
		$errors = $this->reported([self::GUARDED => [ClosedRenderer::class]], [self::GUARDED]);

		self::assertSame(
			["Control 'alsoNope' does not exist on form 'simpleForm' (" . ClosedRenderer::class . ').'],
			$this->messages($errors),
		);
		self::assertSame([5], $this->lines($errors));
	}

	// A template the discovery store links to nothing resolves no form at all. Asserted next to a
	// linked template in the same call, so a rule that silently resolved nothing fails on the other
	// half.
	public function testUnlinkedTemplateYieldsNothingWhileALinkedOneReports(): void
	{
		$errors = $this->reported(
			[self::ABSENT => [ClosedRenderer::class]],
			[self::ABSENT, self::OTHER],
		);

		self::assertSame(
			["Control 'nope' does not exist on form 'simpleForm' (" . ClosedRenderer::class . ').'],
			$this->messages($errors),
		);
	}

	// The reportable set is the ANALYSED template set, never the whole .latte universe or the whole
	// store: `other.latte` is linked and would report, but nobody asked PHPStan to analyse it.
	public function testTemplateOutsideTheAnalysedSetIsNeverReported(): void
	{
		$links = [
			self::ABSENT => [ClosedRenderer::class],
			self::OTHER => [ClosedRenderer::class],
		];

		self::assertCount(2, $this->reported($links, [self::ABSENT, self::OTHER]));
		self::assertSame(
			["Control 'nope' does not exist on form 'simpleForm' (" . ClosedRenderer::class . ').'],
			$this->messages($this->reported($links, [self::ABSENT])),
		);
	}

	// {form X} naming a component that is definitely not a form: no instance of it is a form and no
	// form is an instance of it, so the macro can never render.
	public function testComponentThatIsDefinitelyNotAFormIsReportedAsAnUnknownForm(): void
	{
		$errors = $this->reported([self::ABSENT => [NonFormRenderer::class]], [self::ABSENT]);

		self::assertCount(1, $errors);
		self::assertSame(LatteFormsRule::UNKNOWN_FORM_IDENTIFIER, $errors[0]->getIdentifier());
		self::assertSame(
			"Component 'simpleForm' is not a form (" . NonFormRenderer::class . ').',
			$errors[0]->getMessage(),
		);
		self::assertSame([1], $this->lines($errors));
	}

	// THE WIDENING PIN. IndexShapeResolver falls back to the builder's DECLARED return class, which
	// is "equal or wider than the store's runtime class" - so a component typed Nette\Forms\Container
	// may hand back a real Form at runtime. An ANCESTOR of Form is therefore not evidence of
	// anything, while a class unrelated to Form in both directions is. The two rows differ only in
	// which of those two the component's class is.
	public function testComponentTypedAsAFormAncestorIsNeverReportedAsAnUnknownForm(): void
	{
		self::assertCount(
			1,
			$this->reported([self::ABSENT => [NonFormRenderer::class]], [self::ABSENT]),
			'a class unrelated to Nette\Forms\Form in both directions is provable',
		);

		self::assertSame(
			[],
			$this->messages($this->reported([self::ABSENT => [NotAFormRenderer::class]], [self::ABSENT])),
			'Nette\Forms\Container is an ancestor of Form, so it may be a widened stand-in for one',
		);
	}

	// The same UNRESOLVED-blocks discipline for the form scope itself: a renderer that declares no
	// such component at all proves nothing, so it must cancel the claim the non-form renderer makes.
	public function testARendererThatResolvesNothingBlocksTheUnknownFormReport(): void
	{
		self::assertSame(
			[],
			$this->messages($this->reported(
				[self::ABSENT => [NonFormRenderer::class, BareRenderer::class]],
				[self::ABSENT],
			)),
		);
	}

	// A form resolving on ONE renderer is a form: the non-form sibling contributes nothing, and the
	// controls are then checked against the resolved form as usual.
	public function testOneResolvedFormSilencesTheUnknownFormAndTheControlsAreStillChecked(): void
	{
		$errors = $this->reported(
			[self::ABSENT => [NonFormRenderer::class, ClosedRenderer::class]],
			[self::ABSENT],
		);

		self::assertSame(
			["Control 'nope' does not exist on form 'simpleForm' (" . ClosedRenderer::class . ').'],
			$this->messages($errors),
		);
	}

	// THE TYPE-AWARE CHECKS, all three of them and every silent sibling, out of one processNode()
	// call over one template. Vendor's own compiled output is what makes each row a mismatch:
	// {input}/{inputError}/n:name/{label} all call a method Nette\Forms\Container does not declare,
	// {formContainer} offsets a component that is not an ArrayAccess, and Button::getLabel() is
	// vendor's own `return null`. The silent half is the same macros over components that CAN answer
	// them - including {input send} on that very button, which is how a button is meant to render.
	public function testEveryMacroThatCannotAddressItsComponentIsReportedAndNothingElseIs(): void
	{
		$errors = $this->reported([self::TYPES => [ClosedRenderer::class]], [self::TYPES]);
		$renderer = ' (' . ClosedRenderer::class . ').';

		self::assertSame(
			[
				"Component 'address' on form 'simpleForm' is a container, not a control" . $renderer,
				"Component 'address' on form 'simpleForm' is a container, not a control" . $renderer,
				"Component 'address' on form 'simpleForm' is a container, not a control" . $renderer,
				"Control 'send' on form 'simpleForm' is a " . SubmitButton::class . ', which renders no label' . $renderer,
				"Component 'email' on form 'simpleForm' is a control, not a container" . $renderer,
				"Component 'email' on form 'simpleForm' is a control, not a container" . $renderer,
				"Component 'send' on form 'simpleForm' is a control, not a container" . $renderer,
			],
			$this->messages($errors),
		);
		self::assertSame([2, 3, 4, 5, 6, 7, 8], $this->lines($errors));
	}

	// Three identifiers, one per mismatch, so each can be baselined without silencing the others -
	// and all of them as ignorable and fixer-free as the two the bridge already had.
	public function testEachMismatchCarriesItsOwnIgnorableIdentifier(): void
	{
		$errors = $this->reported([self::TYPES => [ClosedRenderer::class]], [self::TYPES]);

		self::assertSame(
			[
				LatteFormsRule::CONTAINER_AS_CONTROL_IDENTIFIER,
				LatteFormsRule::CONTAINER_AS_CONTROL_IDENTIFIER,
				LatteFormsRule::CONTAINER_AS_CONTROL_IDENTIFIER,
				LatteFormsRule::LABELLESS_CONTROL_IDENTIFIER,
				LatteFormsRule::CONTROL_AS_CONTAINER_IDENTIFIER,
				LatteFormsRule::CONTROL_AS_CONTAINER_IDENTIFIER,
				LatteFormsRule::CONTROL_AS_CONTAINER_IDENTIFIER,
			],
			array_map(static fn (IdentifierRuleError $error): string => $error->getIdentifier(), $errors),
		);

		foreach ($errors as $error) {
			self::assertNotInstanceOf(FixableNodeRuleError::class, $error);
			self::assertNotInstanceOf(NonIgnorableRuleError::class, $error);
			self::assertInstanceOf(FileRuleError::class, $error);
			self::assertSame(self::TEMPLATES . '/' . self::TYPES, $error->getFile());
		}
	}

	// n:label is Latte 2 FormMacros' attribute form of {label}; Latte 3 has <label n:name> only.

	/**
	 * @group latte2
	 */
	public function testNLabelAttributeIsALabelReference(): void
	{
		$errors = $this->reported([self::TYPES_NLABEL => [ClosedRenderer::class]], [self::TYPES_NLABEL]);

		self::assertSame(
			[
				"Control 'send' on form 'simpleForm' is a " . SubmitButton::class . ', which renders no label ('
				. ClosedRenderer::class . ').',
			],
			$this->messages($errors),
		);
		self::assertSame(LatteFormsRule::LABELLESS_CONTROL_IDENTIFIER, $errors[0]->getIdentifier());
		self::assertSame([2], $this->lines($errors));
	}

	// The multi-renderer rule, positive direction: the same mismatch on both linked forms, so the
	// message names both - sorted, like every other renderer list this rule prints.
	public function testAMismatchHoldingOnEveryLinkedRendererNamesThemAll(): void
	{
		$errors = $this->reported(
			[self::TYPES => [TypeAgreeingRenderer::class, ClosedRenderer::class]],
			[self::TYPES],
		);

		self::assertSame(
			"Component 'address' on form 'simpleForm' is a container, not a control ("
			. ClosedRenderer::class . ', ' . TypeAgreeingRenderer::class . ').',
			$this->messages($errors)[0],
		);
		self::assertCount(7, $errors);
	}

	// The multi-renderer rule, negative direction: one linked renderer on which every one of these
	// macros is CORRECT is the shared-partial pattern, and it silences all seven. Detection power
	// comes from the row above, which differs only in the second renderer.
	public function testARendererTheMacroFitsSilencesTheMismatchEntirely(): void
	{
		self::assertSame(
			[],
			$this->messages($this->reported(
				[self::TYPES => [ClosedRenderer::class, TypeDivergentRenderer::class]],
				[self::TYPES],
			)),
		);
	}

	// UNRESOLVED BLOCKS, for the type question exactly as for the existence one: a linked renderer
	// whose component does not resolve may be the one that renders this template, so what the others
	// say about the name is a subset, never the whole truth.
	public function testAnUnresolvedRendererBlocksTheTypeMismatchToo(): void
	{
		self::assertSame(
			[],
			$this->messages($this->reported(
				[self::TYPES => [ClosedRenderer::class, BareRenderer::class]],
				[self::TYPES],
			)),
		);
	}

	// UNRESOLVED BLOCKS one level further in, where the site is NOT skipped: both renderers resolve
	// the form and declare every referenced name, but one of them cannot say what those names ARE.
	// Skipping it and reporting what the other proves is the false-positive shape the whole feature
	// is built to avoid - so it must silence all seven findings the first renderer alone makes.
	public function testARendererWhoseComponentIdentitiesAreUnknownBlocksTheMismatchesTheOtherProves(): void
	{
		self::assertCount(
			7,
			$this->reported([self::TYPES => [ClosedRenderer::class]], [self::TYPES]),
			'the identifiable renderer alone reports every one of them',
		);

		self::assertSame(
			[],
			$this->messages($this->reported(
				[self::TYPES => [ClosedRenderer::class, LostFieldRenderer::class]],
				[self::TYPES],
			)),
		);
	}

	// THE REACH ROW, and the one thing that separates this check's gate from the absence check's. The
	// same form, in one call, answers BOTH: `nope` cannot be called absent because a dynamic add left
	// the name set unfinished, while `inner` is still definitely a container, because no amount of
	// unenumerated ADDING can rebind a name the walk did read (addComponent throws on a duplicate).
	public function testAFormTooOpenToProveAbsenceStillAnswersWhatItsKnownComponentsAre(): void
	{
		$errors = $this->reported([self::TYPES_OPEN => [ConditionalRenderer::class]], [self::TYPES_OPEN]);

		self::assertSame(
			[
				"Component 'inner' on form 'openRootForm' is a container, not a control ("
				. ConditionalRenderer::class . ').',
			],
			$this->messages($errors),
			'the absence check is silent on line 3 of the same template, on the same shape',
		);
		self::assertSame([2], $this->lines($errors));
	}

	// The other half of that gate: the unknown reasons that mean a component may have been LOST -
	// here a statically-named child pulled out of the form - can rebind a name, so they block. The
	// companion template reports from the same call, so a rule that went silent everywhere fails.
	public function testAFormThatMayHaveLostAComponentIsNeverTypeChecked(): void
	{
		$errors = $this->reported(
			[self::TYPES_LOST => [LostFieldRenderer::class], self::ABSENT => [ClosedRenderer::class]],
			[self::TYPES_LOST, self::ABSENT],
		);

		self::assertSame(
			["Control 'nope' does not exist on form 'simpleForm' (" . ClosedRenderer::class . ').'],
			$this->messages($errors),
		);
	}

	// Same gate, other input: a form reached from outside its builder may have the very component
	// removed and re-added as something else, so nothing its shape says about a name's kind holds.
	public function testAnExternallyMutatedFormIsNeverTypeChecked(): void
	{
		$errors = $this->reported(
			[self::TYPES_MUTATED => [TypeMutatedRenderer::class], self::ABSENT => [ClosedRenderer::class]],
			[self::TYPES_MUTATED, self::ABSENT],
		);

		self::assertSame(
			["Control 'nope' does not exist on form 'simpleForm' (" . ClosedRenderer::class . ').'],
			$this->messages($errors),
		);
	}

	// Presence and kind are independent axes here too: a container the builder only SOMETIMES
	// attaches is still a container on every path that has it, and {input} can address it on none.
	public function testAConditionallyAttachedContainerIsStillAContainer(): void
	{
		$errors = $this->reported(
			[self::TYPES_CONDITIONAL => [ConditionalRenderer::class]],
			[self::TYPES_CONDITIONAL],
		);

		self::assertSame(
			[
				"Component 'maybe' on form 'conditionalForm' is a container, not a control ("
				. ConditionalRenderer::class . ').',
			],
			$this->messages($errors),
		);
	}

	public function testDisabledFormsReportsNothing(): void
	{
		self::assertSame(
			[],
			$this->reported([self::ABSENT => [ClosedRenderer::class]], [self::ABSENT], TestGuard::bridge(false)),
		);
	}

	public function testDisabledLatteReportsNothing(): void
	{
		self::assertSame(
			[],
			$this->reported([self::ABSENT => [ClosedRenderer::class]], [self::ABSENT], TestGuard::bridge(true, false)),
		);
	}

	public function testDisabledDiscoveryStoreReportsNothing(): void
	{
		self::assertSame(
			[],
			$this->reported(
				[self::ABSENT => [ClosedRenderer::class]],
				[self::ABSENT],
				TestGuard::bridge(true, true, false),
			),
		);
	}

	// Both identifiers must stay baselineable, and neither may carry a fixer: the rule under-detects
	// by construction (every unproven situation is silent), so an automated edit would delete
	// markup that is correct.
	public function testFindingsAreBaselineableAndCarryNoFixPayload(): void
	{
		$errors = $this->reported(
			[self::ABSENT => [ClosedRenderer::class], self::OTHER => [NonFormRenderer::class]],
			[self::ABSENT, self::OTHER],
		);

		self::assertCount(2, $errors);
		foreach ($errors as $error) {
			self::assertNotInstanceOf(FixableNodeRuleError::class, $error);
			self::assertNotInstanceOf(NonIgnorableRuleError::class, $error);
		}
	}

	/**
	 * @param array<string, list<string>> $rendererClassesByTemplate
	 * @param list<string> $analysedTemplates
	 * @return list<IdentifierRuleError>
	 */
	private function reported(
		array $rendererClassesByTemplate,
		array $analysedTemplates,
		?ConfigurationGuard $guard = null
	): array
	{
		$universe = new LatteUniverse([self::TEMPLATES], self::TEMPLATES);
		$rule = new LatteFormsRule(
			$guard ?? TestGuard::bridge(),
			new FormMacroCollector($universe, TestAdapter::accessor()),
			new FormPairing($this->storeWith($rendererClassesByTemplate), $this->makeResolver()),
			new MacroSuitability(self::createReflectionProvider()),
			$universe,
		);

		return $rule->processNode($this->collectedDataNode($analysedTemplates), $this->scope());
	}

	/**
	 * @param list<IdentifierRuleError> $errors
	 * @return list<string>
	 */
	private function messages(array $errors): array
	{
		return array_map(static fn (IdentifierRuleError $error): string => $error->getMessage(), $errors);
	}

	/**
	 * @param list<IdentifierRuleError> $errors
	 * @return list<int>
	 */
	private function lines(array $errors): array
	{
		$lines = [];
		foreach ($errors as $error) {
			self::assertInstanceOf(LineRuleError::class, $error);
			$lines[] = $error->getLine();
		}

		return $lines;
	}

	/**
	 * @param list<string> $analysedTemplates
	 */
	private function collectedDataNode(array $analysedTemplates): CollectedDataNode
	{
		return new CollectedDataNode(
			$analysedTemplates === []
				? []
				: [self::TEMPLATES . '/any.latte' => [LatteAnalyzedFileMarkerCollector::class => $analysedTemplates]],
			false,
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
