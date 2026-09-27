<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Component\Attachment;

use ArrayAccess as PhpArrayAccess;
use Nette\Application\UI\Component as UiComponent;
use Nette\Application\UI\Control;
use Nette\Application\UI\Form as UiForm;
use Nette\Application\UI\Presenter;
use Nette\ComponentModel\ArrayAccess;
use Nette\ComponentModel\Component;
use Nette\ComponentModel\Container;
use Nette\ComponentModel\IComponent;
use Nette\Forms\Container as FormsContainer;
use Nette\Forms\Controls\BaseControl;
use Nette\Forms\Controls\TextInput;
use Nette\Forms\Form as NetteForm;
use Nette\InvalidStateException;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Component\Attachment\AttachmentTransitions;
use OriPhpstan\Nette\Component\Attachment\ContainerLazyRead;
use OriPhpstan\Nette\Component\Attachment\ParentAccessors;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function array_merge;
use function array_slice;
use function count;
use function explode;
use function implode;
use function preg_match;
use function sort;

/**
 * The freshness gate for the component-model facts the transitions are literal about.
 *
 * AttachmentTransitions names four vendor methods and relies on more than their existence: which
 * argument carries the component, that getComponent() throws by default rather than returning null,
 * and that setParent() takes its parent first and nullably. None of that is expressed anywhere the
 * type system would notice a `composer update` moving it - a renamed method simply stops being
 * recognised and every answer quietly degrades to Maybe, which is sound and useless, and a REORDERED
 * argument is worse than useless because the recognition still fires on the wrong operand.
 *
 * This is the component-model sibling of VendorCatalogFreshnessTest and runs in the same gate. It
 * reads the installed classes rather than a hand-copied signature list, so the only way to satisfy
 * it is for the vendor API to still be the one the transitions were written against.
 */
final class ComponentModelApiFreshnessTest extends BaseTestCase
{

	/**
	 * The classes a lookup-backed accessor can be declared on, which is the set the omission scan
	 * covers. Nette\Forms\Form and Nette\Application\UI\Presenter are in it although they contribute
	 * no entry: each OVERRIDES an accessor to answer itself instead of looking up, and the scan
	 * finding one of them would mean an override had turned back into a lookup.
	 */
	private const ACCESSOR_CLASSES = [
		Component::class,
		BaseControl::class,
		FormsContainer::class,
		NetteForm::class,
		UiComponent::class,
		UiForm::class,
		Presenter::class,
	];

	public function testTheContainerMethodsTheTransitionsNameStillExist(): void
	{
		self::assertTrue(
			(new ReflectionMethod(Container::class, AttachmentTransitions::ADD_COMPONENT))->isPublic(),
		);
		self::assertTrue(
			(new ReflectionMethod(Container::class, AttachmentTransitions::REMOVE_COMPONENT))->isPublic(),
		);
		self::assertTrue(
			(new ReflectionMethod(Container::class, AttachmentTransitions::GET_COMPONENT))->isPublic(),
		);
		self::assertTrue(
			(new ReflectionMethod(Component::class, AttachmentTransitions::SET_PARENT))->isPublic(),
		);
	}

	/**
	 * Argument 0 is the component in both, which is what the transitions read. addComponent()'s NAME
	 * is argument 1 - the one registering signature in the whole model whose name is not first - and
	 * a swap of the two would turn the attach recognition onto a string.
	 */
	public function testTheComponentIsTheFirstArgumentOfAddAndRemove(): void
	{
		self::assertSame('component', $this->parameterName(Container::class, AttachmentTransitions::ADD_COMPONENT, 0));
		self::assertSame('name', $this->parameterName(Container::class, AttachmentTransitions::ADD_COMPONENT, 1));
		self::assertSame(
			'component',
			$this->parameterName(Container::class, AttachmentTransitions::REMOVE_COMPONENT, 0),
		);
	}

	/**
	 * The one-argument call is the definite one: with $throw left at true a missing component throws
	 * instead of returning null, so a returning call yields an attached component. A changed default
	 * would make every one-argument read a possible null and the Yes claim wrong.
	 */
	public function testGetComponentStillThrowsByDefault(): void
	{
		$method = new ReflectionMethod(Container::class, AttachmentTransitions::GET_COMPONENT);
		$parameters = $method->getParameters();

		self::assertCount(2, $parameters);
		self::assertSame('name', $parameters[0]->getName());
		self::assertSame('throw', $parameters[1]->getName());
		self::assertTrue($parameters[1]->isDefaultValueAvailable());
		self::assertTrue($parameters[1]->getDefaultValue());
	}

	/**
	 * ContainerLazyRead proves a read attaches nothing partly off the factory convention: no
	 * `createComponent<Ucname>` on the receiver means `createComponent()` hands back null and the read
	 * adds nothing. Two vendor facts hold that up — the prefix itself, and the `ucfirst($name) !== $name`
	 * clause that makes a capitalised child factory-less by construction — and both are read out of the
	 * installed body rather than trusted. A changed prefix would make every no-factory proof a claim
	 * about a method Nette no longer consults, which is the direction that INVENTS an inert read.
	 */
	public function testCreateComponentStillDispatchesOnTheUcfirstFactoryConvention(): void
	{
		$withFactory = new class extends Container implements PhpArrayAccess {

			use ArrayAccess;

			protected function createComponentSub(): Container
			{
				return new Container();
			}

		};

		self::assertSame(
			'createComponentSub',
			ContainerLazyRead::factoryMethodFor('sub'),
			'the convention the proof is derived from',
		);

		// Array access rather than the method spelling, which the project's own rule forbids:
		// offsetGet() delegates to getComponent() outright and offsetExists() to its no-throw form, so
		// the lazy block under test is the same one either way.
		self::assertInstanceOf(Container::class, $withFactory['sub']);
		self::assertSame(['sub'], $this->childNames($withFactory), 'the read ATTACHED, it did not only return');

		// ucfirst($name) === $name, so createComponent() consults no factory at all and the read
		// attaches nothing although a same-named factory is declared one line up.
		self::assertFalse(isset($withFactory['Sub']));
		self::assertSame(['sub'], $this->childNames($withFactory));
	}

	/**
	 * The other half of the same proof, and the one that keeps `$form->getComponent('nope')`
	 * reportable: with no factory for the name, createComponent() hands back null and nothing is
	 * added — the read then throws, which is the absence rule's business rather than this fact's.
	 */
	public function testAReadWithNoFactoryAttachesNothing(): void
	{
		$bare = new class extends Container implements PhpArrayAccess {

			use ArrayAccess;

		};

		self::assertFalse(isset($bare['sub']));
		self::assertSame([], $this->childNames($bare));
	}

	/**
	 * The half of the lazy block ContainerLazyRead::holdsChildAfterRead() answers from, and the one
	 * that reads backwards: `isset($container['x'])` is `getComponent('x', false) !== null`, so a
	 * FACTORY makes it TRUE - the check runs it and attaches what it produces. If Nette ever made
	 * offsetExists() a plain `isset($this->components[$name])`, every always-TRUE report built on a
	 * factory would become a claim about a check that answers false, which is the direction that
	 * INVENTS. Nothing in a signature says which of the two it is; only running it does.
	 */
	public function testAnExistenceCheckRunsTheFactoryAndSoAnswersTrue(): void
	{
		$withFactory = new class extends Container implements PhpArrayAccess {

			use ArrayAccess;

			public int $factoryRuns = 0;

			protected function createComponentSub(): Container
			{
				$this->factoryRuns++;

				return new Container();
			}

		};

		self::assertTrue(isset($withFactory['sub']), 'the check answers TRUE for a name only a factory can supply');
		self::assertSame(1, $withFactory->factoryRuns, 'and it answered true by RUNNING the factory');
		self::assertSame(['sub'], $this->childNames($withFactory), 'which attached the child as a side effect');

		// The always-FALSE half: neither held nor buildable.
		self::assertFalse(isset($withFactory['nope']));
		self::assertSame(['sub'], $this->childNames($withFactory));

		// A child already held skips the lazy block outright, so no factory runs for it. Read
		// through offsetGet rather than a second isset, which PHPStan's own isset.offset check
		// would report as narrowed by the first one.
		self::assertInstanceOf(Container::class, $withFactory['sub']);
		self::assertSame(1, $withFactory->factoryRuns);
	}

	/**
	 * The separator ContainerLazyRead refuses a name on rather than splitting it: getComponent()
	 * explodes on it and applies the lazy block to the first segment only.
	 */
	public function testTheNameSeparatorIsStillTheOneJoinedNamesAreRefusedOn(): void
	{
		self::assertSame('-', IComponent::NameSeparator);
	}

	/**
	 * setParent() takes the parent first and nullably, and the null spelling is the detach. The
	 * transitions read a literal `null` there and claim No from it.
	 */
	public function testSetParentTakesANullableParentFirst(): void
	{
		$parameters = (new ReflectionMethod(Component::class, AttachmentTransitions::SET_PARENT))->getParameters();

		self::assertGreaterThanOrEqual(1, count($parameters));
		self::assertSame('parent', $parameters[0]->getName());

		$type = $parameters[0]->getType();
		self::assertInstanceOf(ReflectionNamedType::class, $type);
		self::assertTrue($type->allowsNull());
	}

	/**
	 * The accessor table, behaviourally. Each entry claims a detached receiver makes the method
	 * throw, and each is checked by detaching a real component and calling it - a signature read
	 * would not do, because the entry no signature describes is exactly the one that matters:
	 * `Nette\Application\UI\Component::getPresenter()` declares no parameters and takes one anyway,
	 * through func_get_arg(0).
	 *
	 * @dataProvider provideThrowingAccessors
	 */
	public function testEachThrowingAccessorReallyThrowsOnADetachedComponent(
		string $declaringClass,
		string $method,
		?int $throwArgument
	): void
	{
		$component = $this->detachedInstanceOf($declaringClass);
		if ($component === null) {
			// Nette\Application\UI\Form is the one entry with no constructible stand-in: the
			// project's own architecture rule forbids constructing it, so its half of the gate is
			// the source-level pin below.
			self::assertSame(UiForm::class, $declaringClass);

			return;
		}

		// Whatever sits before $throw is a type to look for, and null - the topmost ancestor - is
		// the one every entry accepts.
		$leading = [];
		for ($position = 0; $position < ($throwArgument ?? 0); $position++) {
			$leading[] = null;
		}

		$threw = false;
		try {
			$component->$method(...$leading);
		} catch (InvalidStateException $e) {
			$threw = true;
		}

		self::assertTrue($threw, "$declaringClass::$method() no longer throws on a detached component");

		if ($throwArgument === null) {
			self::assertNull(
				ParentAccessors::noThrowSpelling($method),
				"$method() takes no \$throw, so it must offer no no-throw spelling",
			);

			return;
		}

		// @ because getPresenter()'s $throw is deprecated as well as invisible.
		self::assertNull(
			@$component->$method(...array_merge($leading, [false])),
			"$method() with \$throw false must answer null",
		);
	}

	/**
	 * @return iterable<string, array{string, string, int|null}>
	 */
	public function provideThrowingAccessors(): iterable
	{
		foreach (ParentAccessors::all() as $entry) {
			yield $entry[0] . '::' . $entry[1] => $entry;
		}
	}

	/**
	 * Every entry is a method that REACHES lookup(), read out of the installed body rather than
	 * assumed. This is also the whole of the gate for Nette\Application\UI\Form::getPresenter(),
	 * which cannot be constructed here.
	 *
	 * @dataProvider provideThrowingAccessors
	 */
	public function testEachThrowingAccessorStillRoutesThroughLookup(
		string $declaringClass,
		string $method,
		?int $throwArgument
	): void
	{
		$body = $this->sourceOf($declaringClass, $method);

		if ($this->isTheRootLookup($declaringClass, $method)) {
			// It does not reach lookup(), it IS lookup: the throw itself, guarded by $throw.
			self::assertStringContainsString('throw new Nette\InvalidStateException', $body);
			self::assertStringContainsString('if ($throw && ', $body);

			return;
		}

		self::assertMatchesRegularExpression(
			'~lookup(Path)?\(~',
			$body,
			"$declaringClass::$method() no longer reaches lookup()",
		);
		self::assertStringNotContainsString(
			'return $this;',
			$body,
			"$declaringClass::$method() answers itself now, so it cannot throw",
		);
	}

	/**
	 * The table going stale by OMISSION is the failure a per-entry check cannot see: a Nette release
	 * adding another lookup-backed accessor would simply go unreported. So the accessor classes are
	 * scanned for every public method that reaches lookup(), and the result must be the table plus
	 * the methods that reach it with $throw hardcoded to false.
	 *
	 * Component::lookup() itself is the root - it does not call lookup(), it IS it - and is
	 * therefore named rather than scanned for.
	 */
	public function testNoLookupBackedAccessorIsMissingFromTheTable(): void
	{
		$notAlwaysThrowing = [
			// hands lookup() a hardcoded false
			Component::class . '::monitor',
			UiComponent::class . '::getPresenterIfExists',
			UiComponent::class . '::hasPresenter',
			UiForm::class . '::getPresenterIfExists',
			UiForm::class . '::hasPresenter',
			// looks up on its ARGUMENT, so it says nothing about its own receiver
			Presenter::class . '::isSignalReceiver',
			// reaches lookupPath() only when the html attribute it prefers is unset, and whether it
			// is set is not a question about attachment - so a report would be a Maybe
			BaseControl::class . '::getHtmlName',
			BaseControl::class . '::getHtmlId',
		];

		$tabled = [];
		foreach (ParentAccessors::all() as $entry) {
			$tabled[] = $entry[0] . '::' . $entry[1];
		}

		$found = [];
		foreach (self::ACCESSOR_CLASSES as $class) {
			foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				$name = $method->getName();
				if ($method->getDeclaringClass()->getName() !== $class) {
					continue;
				}

				if (
					$this->isTheRootLookup($class, $name)
					|| preg_match('~lookup(Path)?\(~', $this->sourceOf($class, $name)) === 1
				) {
					$found[] = $class . '::' . $name;
				}
			}
		}

		sort($found);
		$expected = array_merge($tabled, $notAlwaysThrowing);
		sort($expected);

		self::assertSame(
			$expected,
			$found,
			'a lookup-backed accessor appeared or disappeared: add it to ParentAccessors, or to this '
			. 'test\'s no-throw list with the reason it cannot throw',
		);
	}

	private function parameterName(string $class, string $method, int $index): ?string
	{
		$parameters = (new ReflectionMethod($class, $method))->getParameters();

		return isset($parameters[$index]) ? $parameters[$index]->getName() : null;
	}

	/**
	 * A component of the given declaring class with no parent, or null where the project's own
	 * architecture rules forbid constructing one.
	 */
	private function detachedInstanceOf(string $declaringClass): ?IComponent
	{
		if ($declaringClass === UiComponent::class) {
			return new class extends Control {

			};
		}

		if ($declaringClass === FormsContainer::class) {
			return new FormsContainer();
		}

		if ($declaringClass === UiForm::class) {
			return null;
		}

		// Component and BaseControl alike: a TextInput is both, and nothing has added it.
		return new TextInput();
	}

	private function isTheRootLookup(string $class, string $method): bool
	{
		return $class === Component::class && $method === ParentAccessors::LOOKUP;
	}

	private function sourceOf(string $class, string $method): string
	{
		$reflection = new ReflectionMethod($class, $method);
		$lines = explode("\n", FileSystem::read((string) $reflection->getFileName()));
		$start = (int) $reflection->getStartLine();

		return implode("\n", array_slice($lines, $start, (int) $reflection->getEndLine() - $start));
	}

	/**
	 * @return list<string>
	 */
	private function childNames(Container $container): array
	{
		$names = [];
		foreach ($container->getComponents() as $name => $_component) {
			$names[] = (string) $name;
		}

		return $names;
	}

}
