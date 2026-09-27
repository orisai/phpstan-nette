<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use OriPhpstan\Nette\Forms\Catalog\Stub\ControlAnnotationValueTypeReader;
use OriPhpstan\Nette\Forms\Index\FileFactIndex;
use OriPhpstan\Nette\Forms\Index\IndexShapeResolver;
use OriPhpstan\Nette\Forms\Index\RegistrationFact;
use OriPhpstan\Nette\Forms\Index\RegistrationIndex;
use OriPhpstan\Nette\Forms\Index\RegistrationRecognizer;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use OriPhpstan\Nette\Forms\Shape\UnknownReason;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\File\FileFinder;
use PHPStan\Parser\Parser;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Type\VerbosityLevel;
use Tests\OriPhpstan\Nette\Toolkit\FormShapeTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestFileFinder;
use Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\CountingRegistrationIndex;
use function array_map;
use function assert;
use function getcwd;
use function is_dir;
use function sys_get_temp_dir;
use function uniqid;

final class IndexShapeResolverTest extends FormShapeTestCase
{

	private const NS = 'Tests\\OriPhpstan\\Nette\\Unit\\Forms\\Index\\Fixtures';

	private const FIXTURES = __DIR__ . '/Fixtures';

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

	/**
	 * HandlerForm builds `$form = new Form(); $form->addText(...); $form->addInteger(...)` and adds
	 * nothing else, so the handler param resolves CLOSED — the commonest form-building spelling
	 * there is, and the one on which absence reporting is worth the most. The zero-argument bare
	 * Nette base construction contributes a provably empty shape rather than a CONSTRUCTOR_BUILD
	 * open, because the constructed class is resolved through ConstructedFormShapeResolver like
	 * every other `new X()` spelling; asking ConstructorFormShapeResolver directly would read
	 * Nette\Application\UI\Form::__construct in isolation and reject the `$parent->addComponent(...)`
	 * inside `if ($parent !== null)` that no zero-argument construction can reach.
	 */
	public function testSingleSiteHandlerKeyResolvesClosed(): void
	{
		$shape = $this->makeResolver([self::FIXTURES])
			->resolveMethodParam(self::NS . '\HandlerForm', 'orderSucceeded', 0);

		self::assertNotNull($shape);
		self::assertShape($shape, <<<'OUTPUT'
			Nette\Application\UI\Form{
			  name: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  qty: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, int|null>,
			}
			OUTPUT);
	}

	public function testSelfConstructedFactoryReturnResolvesClosedWithTheSiteClass(): void
	{
		$shape = $this->makeResolver([self::FIXTURES])
			->resolveMethodParam(self::NS . '\SelfFactoryForm', 'selfSucceeded', 0);

		self::assertNotNull($shape);
		self::assertShape($shape, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\SelfFactoryForm{
			  title: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT, 'the constructor-declared control resolves through the new self() origin and closes');
	}

	public function testDoubleRebindStaysOpen(): void
	{
		// The registering method rebinds the form twice ($form = parent::createComponentForm(); $form =
		// $this->prebuilt;), which defeats the scope-free class tracker. Bound to a foreign scope that
		// cannot type the form variable, the walk must still degrade OPEN with the visible field, never
		// collapse to a narrower closed-empty shape.
		$scope = $this->scopeInMethod(self::FIXTURES . '/ForeignScope.php', 'noForm');
		$shape = $this->makeResolverBoundTo([self::FIXTURES], $scope)
			->resolveMethodParam(self::NS . '\DoubleRebindForm', 'process', 0);

		self::assertNotNull($shape);
		self::assertShape($shape, <<<'OUTPUT'
			Nette\Application\UI\Form{
			  local: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT, 'the field added after the double rebind survives and the shape stays open');
	}

	public function testAliasedFactoryRebindStaysOpen(): void
	{
		// The form is built through a local-var-aliased factory ($service = $this->factory; $form =
		// $service->create()) with a conditional add. The scope-free walk cannot class the aliased
		// receiver; bound to a foreign scope that cannot type the form variable, the index must degrade
		// OPEN with the visible fields, never collapse to a narrower closed-empty shape.
		$scope = $this->scopeInMethod(self::FIXTURES . '/ForeignScope.php', 'noForm');
		$shape = $this->makeResolverBoundTo([self::FIXTURES], $scope)
			->resolveMethodParam(self::NS . '\AliasedFactoryForm', 'process', 0);

		self::assertNotNull($shape);
		self::assertShape($shape, <<<'OUTPUT'
			Nette\Application\UI\Form{
			  name: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  note?: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT, 'the field added after the aliased factory rebind survives and the shape stays open');
	}

	public function testDirectFactoryPropertyResolvesClosed(): void
	{
		// $form = $this->factory->create() with a factory returning `new FactoryBuiltForm()`: the resolver
		// resolves the factory through the interprocedural machinery, merges its constructor field and
		// clears REBIND_UNPROVEN.
		$shape = $this->makeResolver([self::FIXTURES])
			->resolveMethodParam(self::NS . '\FactoryPropForm', 'factorySucceeded', 0);

		self::assertNotNull($shape);
		self::assertShape($shape, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\FactoryBuiltForm{
			  fromFactory: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  local: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT, 'the factory-built field merges beside the consumer local and the rebind marker clears');
	}

	public function testVarArmFactoryResolves(): void
	{
		// $form = $this->factory->build() where build() constructs and returns a form variable: the
		// resolver walks the remote factory method's var-return arm through the shared analyzer.
		$shape = $this->makeResolver([self::FIXTURES])
			->resolveMethodParam(self::NS . '\VarArmFactoryForm', 'varArmSucceeded', 0);

		self::assertNotNull($shape);
		self::assertShape($shape, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\PlainFactoryForm{
			  fromBuild: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  local: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT, 'the field the factory adds merges beside the consumer local');
	}

	public function testAliasedResolvingFactoryResolvesClosed(): void
	{
		// $service = $this->factory; $form = $service->create(): the aliased receiver is de-aliased onto
		// the property fetch before the scope-free receiver-class resolution, so the resolvable factory
		// now closes (unlike AliasedFactoryForm whose factory returns a prebuilt property fetch).
		$shape = $this->makeResolver([self::FIXTURES])
			->resolveMethodParam(self::NS . '\AliasedResolvingFactoryForm', 'aliasedSucceeded', 0);

		self::assertNotNull($shape);
		self::assertShape($shape, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\FactoryBuiltForm{
			  fromFactory: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  local: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT, 'the de-aliased factory receiver resolves, merges its shape and closes');
	}

	public function testUnfollowableFactoryReturnStaysOpen(): void
	{
		// A classable factory receiver whose create() returns a prebuilt property fetch: the interprocedural
		// walk cannot follow that origin, so the factory shape is null and REBIND_UNPROVEN survives — open
		// with the visible fields, never a narrower closed shape.
		$shape = $this->makeResolver([self::FIXTURES])
			->resolveMethodParam(self::NS . '\UnfollowableFactoryForm', 'unfollowableSucceeded', 0);

		self::assertNotNull($shape);
		self::assertShape($shape, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\FactoryBuiltForm{
			  local: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT, 'the field added after the rebind survives and an unfollowable factory return stays open');
	}

	public function testParentInheritedBuilderResolvesMerging(): void
	{
		// $form = parent::createComponentForm() with the parent building a container + fields: the resolver
		// resolves the parent class's builder scope-free through registeredFormShapeFor and merges its
		// inherited fields.
		$shape = $this->makeResolver([self::FIXTURES])
			->resolveMethodParam(self::NS . '\InheritedParentForm', 'formSucceeded', 0);

		self::assertNotNull($shape);
		self::assertShape($shape, <<<'OUTPUT'
			Nette\Application\UI\Form{
			  childField: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  filter: Nette\Forms\Container{
			    inFilter: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  },
			  parentField: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT, 'the parent-built field merges beside the child-added one');
	}

	public function testParentInheritedContainerMutatedViaGetComponentStaysOpen(): void
	{
		// $form = parent::createComponentForm(); $filter = $form->getComponent('filter'); $filter->addText(...):
		// the child pulls the parent-inherited container into a local and mutates it — an add the scope-free
		// walk cannot attribute. Merging the parent's precise-but-now-incomplete 'filter' would wrongly close
		// it and drop the child's field, so the getComponent read degrades the shape OPEN (honest-open), the
		// same widening the offset spelling ($filter = $form['filter']) already triggers.
		$shape = $this->makeResolver([self::FIXTURES])
			->resolveMethodParam(self::NS . '\InheritedParentMutatedForm', 'formSucceeded', 0);

		self::assertNotNull($shape);
		self::assertShape($shape, <<<'OUTPUT'
			Nette\Application\UI\Form{
			  ...<IComponent>,
			}
			OUTPUT, 'the parent-inherited container is not merged closed over the child mutation');
		// The render shows the shape is open but not which reason opened it, and the reason is the
		// point of this test: CONTAINER_REFERENCE is what bails the parent merge.
		self::assertContains(
			UnknownReason::CONTAINER_REFERENCE,
			$shape->getUnknown()->getReasons(),
			'the getComponent read raises the lost-field marker that bails the parent merge',
		);
	}

	public function testFactoryBuiltFieldsNotMergedOverGetComponentMutation(): void
	{
		// $form = $this->factory->build(); $section = $form->getComponent('section'); $section->addText(...):
		// the consumer pulls a factory-built container into a local and mutates it — an add the scope-free
		// walk cannot attribute. Merging the factory's precise-but-now-incomplete fields would wrongly close
		// the shape and launder the rebind marker, so the getComponent read bails the factory compensation:
		// the factory field is not merged and REBIND_UNPROVEN survives (honest-open), symmetric with the
		// parent-inherited container gate.
		$shape = $this->makeResolver([self::FIXTURES])
			->resolveMethodParam(self::NS . '\FactoryMutatedForm', 'factorySucceeded', 0);

		self::assertNotNull($shape);
		self::assertShape($shape, <<<'OUTPUT'
			Nette\Application\UI\Form{
			  ...<IComponent>,
			}
			OUTPUT, 'the factory-built field is not merged closed over the consumer mutation');
		// The render shows the shape is open but not which reasons opened it, and both reasons are
		// the point of this test: the guard fires on CONTAINER_REFERENCE and leaves REBIND_UNPROVEN
		// unstripped rather than laundering it.
		self::assertContains(
			UnknownReason::CONTAINER_REFERENCE,
			$shape->getUnknown()->getReasons(),
			'the getComponent read raises the lost-field marker that triggers the guard',
		);
		self::assertContains(
			UnknownReason::REBIND_UNPROVEN,
			$shape->getUnknown()->getReasons(),
			'the bailed factory compensation leaves the rebind marker unstripped (honest-open)',
		);
	}

	public function testConstructorBuiltControlPulledIntoLocalKeepsShapeClosed(): void
	{
		// $form = new ConstructorFieldForm(); $probe = $form->getComponent('fromCtor');
		// assert($probe instanceof BaseControl); $probe->setDisabled(); — the consumer pulls a
		// constructor-declared control into a local and calls a setter on it. That is the same component
		// set as $form->addText('fromCtor')->setDisabled(), which has always closed, so the assignment
		// alone may not decide it: the read is judged by WHAT the handle is (a leaf control this state
		// resolves a class for, with no add* surface) and by what every use of it does (a type probe and
		// a bare non-registering call). Both hold, so no lost-field marker is raised and the shape stays
		// CLOSED — which is what makes the absence rule able to report here at all. The container
		// spellings next door (a parent-inherited or factory-built CONTAINER pulled out and added to)
		// still open, because a container handle can gain fields and an addText() through it registers.
		$shape = $this->makeResolver([self::FIXTURES])
			->resolveMethodParam(self::NS . '\ConstructorMutatedForm', 'ctorSucceeded', 0);

		self::assertNotNull($shape);
		self::assertShape($shape, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\ConstructorFieldForm{
			  fromCtor: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  kept: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT, 'the pulled-out control and the constructor-declared field are both known, and the shape is closed');
	}

	public function testDynamicParentCallIsNotRecognisedAsParentInherited(): void
	{
		// $form = parent::$method() is a dynamic static call whose name is not an Identifier, so
		// inheritedParentShape does not recognise it as a parent-inherited rebind and the parent's fields
		// are NOT merged — exactly as the store's trigger, which requires an Identifier name, rejects it
		// (contrast testParentInheritedBuilder..., where the Identifier spelling DOES merge parentField).
		// The dynamic origin additionally defeats the scope-free base walk's receiver classing (the
		// pre-existing B1-residual-4 narrowing, orthogonal to this gate and shown by the A9 whole-project
		// shadow to hit zero real code). What the render below pins is this resolver's own answer; it is
		// deliberately NOT a parity comparison against the live store, which is where that residual would
		// show. The empty body carries the absence the two probes here used to spell one name at a time,
		// and the shape stays open rather than the closed-empty an unguarded narrowing would give.
		$shape = $this->makeResolver([self::FIXTURES])
			->resolveMethodParam(self::NS . '\DynamicParentForm', 'dynamicSucceeded', 0);

		self::assertNotNull($shape);
		self::assertShape($shape, <<<'OUTPUT'
			Nette\Application\UI\Form{
			  ...<IComponent>,
			}
			OUTPUT, 'neither the parent field nor the parent container is merged on a dynamic parent call');
	}

	public function testTraitDeclaredParentInheritedBuilderResolves(): void
	{
		// The child createComponentForm is declared in a trait used by a class extending the parent: the
		// fact carries the using class (A5 re-key), so its parent (the base) is the one parent:: resolves
		// to, and the inherited fields merge exactly as for a directly-declared child.
		$shape = $this->makeResolver([self::FIXTURES])
			->resolveMethodParam(self::NS . '\TraitSameFileForm', 'sameFileSucceeded', 0);

		self::assertNotNull($shape);
		self::assertShape($shape, <<<'OUTPUT'
			Nette\Application\UI\Form{
			  childField: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  filter: Nette\Forms\Container{
			    inFilter: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  },
			  parentField: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT, 'the inherited fields merge exactly as for a directly-declared child');
	}

	public function testChainReturnCreateComponentResolvesThroughTheFactoryWalk(): void
	{
		// return $this->factory->build(): the store's chain arm resolves the terminal factory
		// method; the twin reproduces it scope-free (chainCallShape over the class-tracked
		// receiver, then the remote factory walk).
		$shape = $this->makeResolver([self::FIXTURES])
			->classComponentShape(self::NS . '\NonVarReturnForm', 'chainForm');

		self::assertNotNull($shape);
		self::assertShape($shape, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\PlainFactoryForm{
			  fromBuild: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT, 'the chain-returned factory field resolves under the terminal factory class');
	}

	public function testPropertyFetchReturnCreateComponentResolvesOpenWithThePropertyClass(): void
	{
		// return $this->prebuilt: the store's property-fetch arm exposes a prebuilt component the
		// method never builds — class known, fields not, so the twin's shape stays open
		// (UNRESOLVED_ORIGIN), never a closed empty over the unknown content.
		$shape = $this->makeResolver([self::FIXTURES])
			->classComponentShape(self::NS . '\NonVarReturnForm', 'propForm');

		self::assertNotNull($shape);
		self::assertShape($shape, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\FactoryBuiltForm{
			  ...<IComponent>,
			}
			OUTPUT, 'the class is known, no field of the prebuilt component is claimed, and the shape stays open');
		// The render shows the shape is open but not which reason opened it, and the reason is the
		// point of this test: an unfollowable origin, not some incidental unknown.
		self::assertContains(
			UnknownReason::UNRESOLVED_ORIGIN,
			$shape->getUnknown()->getReasons(),
			'a prebuilt property-fetch return stays honestly open',
		);
	}

	public function testPrivatePropertyBuiltInTheConstructorResolvesClosed(): void
	{
		// $this->privateForm = new PlainFactoryForm(); $this->privateForm->addText('name'): the walk
		// speaks only variable names, so the builder body is normalised into that vocabulary and the
		// ordinary fold applies. A private property's whole write set is the declaring class's own
		// bodies, every one of which the fold reads — so this one may close.
		$shape = $this->makeResolver([self::FIXTURES])
			->classPropertyFormShape(self::NS . '\PropertyFormVisibility', 'privateForm');

		self::assertNotNull($shape);
		self::assertShape($shape, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\PlainFactoryForm{
			  name: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT, 'a private property whose every write the fold reads closes over the constructor-added control');
	}

	public function testPublicPropertyStaysOpen(): void
	{
		// The SAME constructor body as the private row, differing only in the property's visibility:
		// an outside $obj->publicForm->addText() is invisible to a per-class fold, so closing here
		// would answer "this field does not exist" about a field that really exists.
		$shape = $this->makeResolver([self::FIXTURES])
			->classPropertyFormShape(self::NS . '\PropertyFormVisibility', 'publicForm');

		self::assertNotNull($shape);
		self::assertShape($shape, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\PlainFactoryForm{
			  name: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT, 'the visible control still resolves and the shape stays open');
		// The render shows the shape is open but not which reason opened it, and the reason is the
		// point of this test: the unmodelled outside write, not some incidental unknown.
		self::assertContains(
			UnknownReason::PROPERTY_WRITE_UNMODELLED,
			$shape->getUnknown()->getReasons(),
			'a publicly writable property-held form stays honestly open',
		);
	}

	public function testProtectedPropertyStaysOpen(): void
	{
		// Same body again: protected is reachable from every subclass body, none of which the queried
		// class's method set contains, so it opens for the same reason public does.
		$shape = $this->makeResolver([self::FIXTURES])
			->classPropertyFormShape(self::NS . '\PropertyFormVisibility', 'protectedForm');

		self::assertNotNull($shape);
		self::assertShape($shape, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\PlainFactoryForm{
			  name: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT, 'protected renders exactly as public does');
		// The render shows the shape is open but not which reason opened it, and the reason is the
		// point of this test: protected opens for the same unmodelled-write reason public does.
		self::assertContains(
			UnknownReason::PROPERTY_WRITE_UNMODELLED,
			$shape->getUnknown()->getReasons(),
			'a protected property-held form stays honestly open',
		);
	}

	public function testBuilderIsAnyAssigningMethodNotOnlyTheConstructor(): void
	{
		// The property is bound by a plain method, never by __construct: the builder rule is "every
		// method that assigns the property", not a hard-coded constructor.
		$shape = $this->makeResolver([self::FIXTURES])
			->classPropertyFormShape(self::NS . '\PropertyFormBuilders', 'lateForm');

		self::assertNotNull($shape);
		self::assertShape($shape, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\PlainFactoryForm{
			  late: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT, 'a non-constructor builder resolves and a sole private binder closes');
	}

	public function testTwoAssigningMethodsLeaveThePropertyShapeOpen(): void
	{
		// first() and second() each rebind the property to a differently-built form. Which one the
		// live object holds is not a per-class fact, so the arms join (both fields MAYBE) and the
		// shape carries the rebind marker rather than claiming either arm's field set.
		$shape = $this->makeResolver([self::FIXTURES])
			->classPropertyFormShape(self::NS . '\PropertyFormBuilders', 'twiceForm');

		self::assertNotNull($shape);
		self::assertShape($shape, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\PlainFactoryForm{
			  a?: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  b?: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT, 'both arms join with neither binder definitely winning, so each field renders maybe-present');
		// The render shows the shape is open but not which reason opened it, and the reason is the
		// point of this test: the binding order, not either arm's content, is what is unproven.
		self::assertContains(
			UnknownReason::REBIND_UNPROVEN,
			$shape->getUnknown()->getReasons(),
			'two unordered binders leave the binding unproven',
		);
	}

	public function testSiblingMutatorMethodOpensTheShape(): void
	{
		// extend() adds to the property-held form without binding it. The fold reads binder bodies, so
		// that add is not in the shape — and a shape missing a member with no unknown reason saying so
		// is exactly the false "this field does not exist" this channel must never produce.
		$shape = $this->makeResolver([self::FIXTURES])
			->classPropertyFormShape(self::NS . '\PropertyFormSiblings', 'mutatedForm');

		self::assertNotNull($shape);
		self::assertShape($shape, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\PlainFactoryForm{
			  base: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT, 'the builder field still resolves and the sibling mutator opens the shape rather than being merged');
		// The render shows the shape is open but not which reason opened it, and the reason is the
		// point of this test: the sibling's unattributable write, not some incidental unknown.
		self::assertContains(
			UnknownReason::PROPERTY_WRITE_UNMODELLED,
			$shape->getUnknown()->getReasons(),
			'a non-binding method that mutates the property-held form opens the shape',
		);
	}

	public function testSiblingReadOnlyMethodKeepsTheShapeClosed(): void
	{
		// read() only calls getValues() on the property-held form. Deciding that by the shared walk
		// rather than by "mentions the property at all" is what keeps this channel useful: the
		// consumer of a property-held form almost always lives in the class that built it.
		$shape = $this->makeResolver([self::FIXTURES])
			->classPropertyFormShape(self::NS . '\PropertyFormSiblings', 'readForm');

		self::assertNotNull($shape);
		self::assertShape($shape, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\PlainFactoryForm{
			  base: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT, 'a read of the property-held form is not a write and must not open the shape');
	}

	public function testPropertyHandedOutOfTheClassStaysOpen(): void
	{
		// getForm() returns the form itself: every caller holds a mutable reference the per-class fold
		// never sees, which is the public-property hazard wearing a method's clothes.
		$shape = $this->makeResolver([self::FIXTURES])
			->classPropertyFormShape(self::NS . '\PropertyFormEscape', 'form');

		self::assertNotNull($shape);
		self::assertShape($shape, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\PlainFactoryForm{
			  base: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT, 'the builder field still resolves and handing the form out opens the shape');
		// The render shows the shape is open but not which reason opened it, and the reason is the
		// point of this test: the escape is the public-property hazard wearing a method's clothes.
		self::assertContains(
			UnknownReason::PROPERTY_WRITE_UNMODELLED,
			$shape->getUnknown()->getReasons(),
			'a returned property-held form escapes the fold and opens the shape',
		);
	}

	public function testForeignInstanceAccessStaysOpen(): void
	{
		// $other->form->addText() inside the declaring class is legal PHP even for a private property,
		// and it names no $this the rewrite can normalise. It stays an unattributable write.
		$shape = $this->makeResolver([self::FIXTURES])
			->classPropertyFormShape(self::NS . '\PropertyFormForeignAccess', 'form');

		self::assertNotNull($shape);
		self::assertShape($shape, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\PlainFactoryForm{
			  base: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT, 'the builder field still resolves and the foreign-instance write opens the shape');
		// The render shows the shape is open but not which reason opened it, and the reason is the
		// point of this test: a same-class other-instance write is still an unattributable write.
		self::assertContains(
			UnknownReason::PROPERTY_WRITE_UNMODELLED,
			$shape->getUnknown()->getReasons(),
			'a same-class other-instance write opens the shape',
		);
	}

	public function testFactoryAssignedPropertyResolvesThroughTheSharedCompensation(): void
	{
		// $this->form = $this->factory->create(): the property channel inherits the fold's factory-rebind
		// compensation unchanged — the factory's constructor field merges in and REBIND_UNPROVEN clears —
		// which is the whole point of normalising into the existing walk instead of writing a second one.
		$shape = $this->makeResolver([self::FIXTURES])
			->classPropertyFormShape(self::NS . '\PropertyFormFactoryOwner', 'form');

		self::assertNotNull($shape);
		self::assertShape($shape, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\FactoryBuiltForm{
			  fromFactory: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  local: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT, 'the property channel inherits the fold factory-rebind compensation unchanged');
	}

	public function testPropertyNoMethodAssignsResolvesToNull(): void
	{
		$shape = $this->makeResolver([self::FIXTURES])
			->classPropertyFormShape(self::NS . '\PropertyFormUnbuilt', 'never');

		self::assertNull($shape, 'a property no method binds anchors no shape');
	}

	public function testPropertyDeclaredAsANonContainerResolvesToNull(): void
	{
		$shape = $this->makeResolver([self::FIXTURES])
			->classPropertyFormShape(self::NS . '\PropertyFormUnbuilt', 'service');

		self::assertNull($shape, 'a property whose declared type can never hold a container is not a form slot');
	}

	public function testPropertyAssignedANonContainerResolvesToNull(): void
	{
		$shape = $this->makeResolver([self::FIXTURES])
			->classPropertyFormShape(self::NS . '\PropertyFormUnbuilt', 'thing');

		self::assertNull($shape, 'an object-typed property bound to a non-container is not a form slot');
	}

	public function testUnknownPropertyResolvesToNull(): void
	{
		$resolver = $this->makeResolver([self::FIXTURES]);

		self::assertNull($resolver->classPropertyFormShape(self::NS . '\PropertyFormVisibility', 'noSuchProperty'));
		self::assertNull($resolver->classPropertyFormShape(self::NS . '\NoSuchClassAtAll', 'form'));
	}

	public function testMultiReturnSiteJoinsFieldsAsMaybePresent(): void
	{
		$resolver = $this->makeResolver([self::FIXTURES]);

		$shape = $resolver->resolveMethodParam(self::NS . '\MultiReturnForm', 'editSucceeded', 0);

		self::assertNotNull($shape);
		self::assertShape($shape, <<<'OUTPUT'
			Nette\Application\UI\Form{
			  name: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  note?: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT, 'a field on every return arm stays present, one on a single arm degrades to maybe');
	}

	public function testTwoSiteKeyMergesBothRegisteredShapes(): void
	{
		$resolver = $this->makeResolver([self::FIXTURES]);

		$shape = $resolver->resolveMethodParam(self::NS . '\TwoSiteForm', 'sharedSucceeded', 0);

		self::assertNotNull($shape);
		self::assertShape($shape, <<<'OUTPUT'
			Nette\Application\UI\Form{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  b: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT, 'both registration sites merge into the handler-param shape');
	}

	public function testTraitDeclaredRegistrationResolvesForUsingClass(): void
	{
		$resolver = $this->makeResolver([self::FIXTURES]);

		$shape = $resolver->resolveMethodParam(self::NS . '\UsesInnerTrait', 'innerSucceeded', 0);

		self::assertNotNull($shape);
		self::assertShape($shape, <<<'OUTPUT'
			Nette\Application\UI\Form{
			  inner: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT, 'a trait-declared registration resolves for the using class');
	}

	public function testPassThroughEdgeResolvesThroughRecursionWhenAllGatesPass(): void
	{
		$resolver = $this->makeResolver([self::FIXTURES]);

		$shape = $resolver->resolveMethodParam(self::NS . '\PassThroughForm', 'fillForm', 0);

		self::assertNotNull($shape, 'the pass-through edge recurses to the registered handler shape');
		self::assertShape($shape, <<<'OUTPUT'
			Nette\Application\UI\Form{
			  field: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	public function testCrossFilePassThroughChainResolvesClosed(): void
	{
		// GdprAgreement pattern: the array-callable handler (gdprSucceeded) hands its form to a helper
		// declared in a trait living in a SEPARATE file (GdprAgreementPersistHelper::persistAgreement).
		// The fold sees the registration, the pass-through edge and the trait use across both files, so
		// the helper param resolves to the registered form closed — the seam that could race this cold
		// is gone.
		$shape = $this->makeResolver([self::FIXTURES])
			->resolveMethodParam(self::NS . '\GdprAgreementChain', 'persistAgreement', 0);

		self::assertNotNull($shape, 'the cross-file pass-through edge resolves the handler form end-to-end');
		self::assertShape($shape, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  agreement: Nette\Forms\Controls\Checkbox<bool|float|int|string|null, bool>,
			  note: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT, 'both registered fields flow through the chain and it resolves closed');
	}

	public function testClassComponentArgEdgeResolvesTheComponentShape(): void
	{
		$resolver = $this->makeResolver([self::FIXTURES]);

		$shape = $resolver->resolveMethodParam(self::NS . '\ClassComponentArgForm', 'outerHelper', 0);

		self::assertNotNull($shape, 'the $this[name] class-component arg resolves through the component shape');
		self::assertShape($shape, <<<'OUTPUT'
			Nette\Application\UI\Form{
			  field: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	public function testClassComponentArgResolvesThroughDownstreamPassThrough(): void
	{
		$resolver = $this->makeResolver([self::FIXTURES]);

		$shape = $resolver->resolveMethodParam(self::NS . '\ClassComponentArgForm', 'innerHelper', 0);

		self::assertNotNull($shape, 'an int-origin edge recurses into a class-component-arg edge');
		self::assertShape($shape, <<<'OUTPUT'
			Nette\Application\UI\Form{
			  field: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

	public function testPassThroughContainmentGateRejectsUnanalysedDeclaringFile(): void
	{
		$resolver = $this->makeResolver([]);

		self::assertNull(
			$resolver->resolveMethodParam(self::NS . '\PassThroughForm', 'fillForm', 0),
			'an out-of-analysed-paths declaring file contributes nothing',
		);
	}

	public function testCalleeMethodGateRejectsUndefinedCallee(): void
	{
		$resolver = $this->makeResolver([self::FIXTURES]);

		self::assertNull(
			$resolver->resolveMethodParam(self::NS . '\GateForm', 'missingCallee', 0),
			'a callee the receiver does not declare contributes nothing',
		);
	}

	public function testCalleeParamTypeGateRejectsNonFormParam(): void
	{
		$resolver = $this->makeResolver([self::FIXTURES]);

		self::assertNull(
			$resolver->resolveMethodParam(self::NS . '\GateForm', 'consumeString', 0),
			'a non-Form callee parameter contributes nothing',
		);
	}

	public function testCallerParamTypeGateRejectsNullableCallerParam(): void
	{
		$resolver = $this->makeResolver([self::FIXTURES]);

		self::assertNull(
			$resolver->resolveMethodParam(self::NS . '\GateForm', 'fillNullable', 0),
			'a nullable ?Form caller parameter fails the precise super-type gate',
		);
	}

	public function testDeclaringClassGateRejectsInheritedCalleeKeyedUnderChild(): void
	{
		$resolver = $this->makeResolver([self::FIXTURES]);

		self::assertNull(
			$resolver->resolveMethodParam(self::NS . '\ChildHandler', 'inheritedFill', 0),
			'an inherited callee is keyed under its declaring parent, not the syntactic child',
		);
	}

	public function testPassThroughCycleTerminatesNull(): void
	{
		$resolver = $this->makeResolver([self::FIXTURES]);

		self::assertNull(
			$resolver->resolveMethodParam(self::NS . '\CycleForm', 'aHandler', 0),
			'a re-entrant pass-through cycle terminates and degrades to null',
		);
	}

	public function testZeroSiteKeyResolvesToNull(): void
	{
		$resolver = $this->makeResolver([self::FIXTURES]);

		self::assertNull($resolver->resolveMethodParam(self::NS . '\HandlerForm', 'noSuchMethod', 0));
	}

	public function testRepeatedResolutionUsesMemoWithoutReQueryingTheFold(): void
	{
		$cache = new FormShapeCache($this->makeDir());
		$counting = new CountingRegistrationIndex([self::FIXTURES], $this->finder(), $this->facts($cache));
		$resolver = $this->makeResolverWith($counting, $cache, [self::FIXTURES]);

		$first = $resolver->resolveMethodParam(self::NS . '\HandlerForm', 'orderSucceeded', 0);
		$positiveQueries = $counting->queryCount(self::NS . '\HandlerForm', 'orderSucceeded', 0);
		$resolver->resolveMethodParam(self::NS . '\HandlerForm', 'orderSucceeded', 0);

		self::assertNotNull($first);
		self::assertGreaterThan(0, $positiveQueries);
		self::assertSame(
			$positiveQueries,
			$counting->queryCount(self::NS . '\HandlerForm', 'orderSucceeded', 0),
			'the positive memo answers a repeated key without re-querying the fold',
		);

		$resolver->resolveMethodParam(self::NS . '\HandlerForm', 'ghostMethod', 0);
		$negativeQueries = $counting->queryCount(self::NS . '\HandlerForm', 'ghostMethod', 0);
		$resolver->resolveMethodParam(self::NS . '\HandlerForm', 'ghostMethod', 0);

		self::assertGreaterThan(0, $negativeQueries);
		self::assertSame(
			$negativeQueries,
			$counting->queryCount(self::NS . '\HandlerForm', 'ghostMethod', 0),
			'the negative cache answers a repeated missing key without re-querying the fold',
		);
	}

	/**
	 * C1(c): the persisted regidx blob is a scalar lowering of the fold's reverse maps
	 * (RegistrationIndex::finalize / hydrate); this pins that loading it back through a FRESH
	 * FormShapeCache instance (a genuine serialize -> unserialize -> hydrate round trip, not an
	 * in-memory reuse of the writer) answers byte-identically to an uncached, freshly-computed fold —
	 * both on the raw reverse maps and through a resolver built on each.
	 */
	public function testFreshFoldEqualsSerializeUnserializeHydrateThroughBothPaths(): void
	{
		$blobDir = $this->makeDir();

		$fresh = new RegistrationIndex(
			[self::FIXTURES],
			$this->finder(),
			$this->facts(new FormShapeCache($this->makeDir())),
		);

		$writerCache = new FormShapeCache($blobDir);
		$written = new RegistrationIndex([self::FIXTURES], $this->finder(), $this->facts($writerCache), $writerCache);
		// Force the fold so the blob is actually persisted to $blobDir before the hydrate path reads it.
		$written->handlerSites(self::NS . '\HandlerForm', 'orderSucceeded', 0);

		$readerCache = new FormShapeCache($blobDir);
		$hydrated = new RegistrationIndex([self::FIXTURES], $this->finder(), $this->facts($readerCache), $readerCache);

		self::assertSame(
			$this->normalizeSites($fresh->handlerSites(self::NS . '\HandlerForm', 'orderSucceeded', 0)),
			$this->normalizeSites($hydrated->handlerSites(self::NS . '\HandlerForm', 'orderSucceeded', 0)),
			'a hydrated handler reverse-map entry must equal the fresh, uncached fold',
		);
		self::assertSame(
			$this->normalizeSites($fresh->passThroughEdgesInto(self::NS . '\PassThroughForm', 'fillForm', 0)),
			$this->normalizeSites($hydrated->passThroughEdgesInto(self::NS . '\PassThroughForm', 'fillForm', 0)),
			'a hydrated pass-through reverse-map entry must equal the fresh, uncached fold',
		);

		$freshAnswer = $this->makeResolverWith($fresh, new FormShapeCache($this->makeDir()), [self::FIXTURES])
			->resolveMethodParam(self::NS . '\HandlerForm', 'orderSucceeded', 0);
		$hydratedAnswer = $this->makeResolverWith($hydrated, new FormShapeCache($this->makeDir()), [self::FIXTURES])
			->resolveMethodParam(self::NS . '\HandlerForm', 'orderSucceeded', 0);

		self::assertNotNull($freshAnswer);
		self::assertNotNull($hydratedAnswer);
		self::assertSame(
			$this->describeShape($freshAnswer),
			$this->describeShape($hydratedAnswer),
			'a resolver answer through the hydrated index must equal the same resolver built on the fresh fold',
		);
	}

	/**
	 * @param list<array{file: string, fact: RegistrationFact}> $sites
	 * @return list<array{file: string, fact: array<string, mixed>}>
	 */
	private function normalizeSites(array $sites): array
	{
		return array_map(
			static fn (array $site): array => ['file' => $site['file'], 'fact' => $site['fact']->toArray()],
			$sites,
		);
	}

	private function describeShape(FormShape $shape): string
	{
		return $shape->getClassName() . $shape->describe(VerbosityLevel::precise());
	}

	/**
	 * @param list<string> $analysedPaths
	 */
	private function makeResolver(array $analysedPaths): IndexShapeResolver
	{
		$cache = new FormShapeCache($this->makeDir());
		$index = new RegistrationIndex([self::FIXTURES], $this->finder(), $this->facts($cache));

		return $this->makeResolverWith($index, $cache, $analysedPaths);
	}

	/**
	 * @param list<string> $analysedPaths
	 */
	private function makeResolverWith(
		RegistrationIndex $index,
		FormShapeCache $cache,
		array $analysedPaths
	): IndexShapeResolver
	{
		$resolver = new IndexShapeResolver(
			$index,
			$cache,
			$this->parser(),
			self::createReflectionProvider(),
			$this->catalogReader(),
			$analysedPaths,
		);
		$resolver->bindScope($this->scope());

		return $resolver;
	}

	/**
	 * @param list<string> $analysedPaths
	 */
	private function makeResolverBoundTo(array $analysedPaths, Scope $scope): IndexShapeResolver
	{
		$cache = new FormShapeCache($this->makeDir());
		$index = new RegistrationIndex([self::FIXTURES], $this->finder(), $this->facts($cache));
		$resolver = new IndexShapeResolver(
			$index,
			$cache,
			$this->parser(),
			self::createReflectionProvider(),
			$this->catalogReader(),
			$analysedPaths,
		);
		$resolver->bindScope($scope);

		return $resolver;
	}

	private function scopeInMethod(string $file, string $functionName): Scope
	{
		$captured = null;
		self::processFile(
			$file,
			static function (Node $node, Scope $scope) use (&$captured, $functionName): void {
				if ($captured === null && $scope->isInClass() && $scope->getFunctionName() === $functionName) {
					$captured = $scope;
				}
			},
		);

		self::assertNotNull($captured);

		return $captured;
	}

	private function facts(FormShapeCache $cache): FileFactIndex
	{
		return new FileFactIndex($cache, $this->parser(), new RegistrationRecognizer());
	}

	private function parser(): Parser
	{
		$parser = self::getContainer()->getService('defaultAnalysisParser');
		assert($parser instanceof Parser);

		return $parser;
	}

	private function finder(): FileFinder
	{
		return TestFileFinder::create((string) getcwd());
	}

	private function catalogReader(): ControlAnnotationValueTypeReader
	{
		return new ControlAnnotationValueTypeReader(
			self::createReflectionProvider(),
			self::getContainer()->getByType(TypeStringResolver::class),
		);
	}

	private function scope(): Scope
	{
		$captured = null;
		self::processFile(
			self::FIXTURES . '/HandlerForm.php',
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
		$dir = sys_get_temp_dir() . '/index-shape-resolver-test-' . uniqid('', true);
		FileSystem::createDir($dir);
		$this->dirs[] = $dir;

		return $dir;
	}

}
