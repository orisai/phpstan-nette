<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Parity\Factory;

use Latte\Engine;
use Nette\Application\UI\Control;
use Nette\Bridges\ApplicationLatte\DefaultTemplate;
use Nette\Bridges\ApplicationLatte\TemplateFactory;
use Nette\Http\Request;
use Nette\Http\UrlScript;
use Nette\Security\User;
use OriPhpstan\Nette\Latte\Bridge\TemplateFactoryDefaultResolver;
use ReflectionProperty;
use Tests\OriPhpstan\Nette\Integration\Latte\Parity\Factory\Fixtures\ParityFlashlessTemplate;
use Tests\OriPhpstan\Nette\Integration\Latte\Parity\Factory\Fixtures\ParityTypedFlashesTemplate;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\FixtureBridgeLatteFactory;
use TypeError;
use function array_key_exists;
use function array_keys;
use function property_exists;
use function sort;

// Vendor-upgrade tripwire for FactoryProvidedVars: every rule the availability source applies when
// deciding whether a template variable exists, and with what value, is proven here against the
// INSTALLED nette/application (v3.1.15) rather than read off its source once. A vendor upgrade that
// moves the goalposts breaks a test instead of turning the analysis model into fiction.
final class TemplateFactoryInjectionParityTest extends BaseTestCase
{

	// The injected keys and the null-valued ones, in one shot. Nothing here has an HTTP request, a
	// user or a control, so five of the six values the factory computes are null and are NOT written
	// - yet every one of them is still a template PARAMETER, because DefaultTemplate declares them
	// untyped and Template::getParameters() exports whatever the property holds (null). That is the
	// runtime fact behind modelling a MAYBE variable as a nullable one rather than an absent one:
	// the body sees the name, so variable.undefined would be a false positive, and isset() on it is
	// genuinely false, so claiming otherwise would be one too.
	public function testFactoryLeavesEveryNullValuedKeyAtItsPropertyDefault(): void
	{
		$parameters = $this->factory()->createTemplate()->getParameters();

		self::assertSame(
			['basePath', 'baseUrl', 'control', 'flashes', 'presenter', 'user'],
			$this->sortedKeys($parameters),
		);
		self::assertNull($parameters['user']);
		self::assertNull($parameters['baseUrl']);
		self::assertNull($parameters['basePath']);
		self::assertNull($parameters['control']);
		self::assertNull($parameters['presenter']);
	}

	// THE ARGUMENT, not the renderer's type, decides $control and $presenter: the same factory called
	// with a control writes $control, while $presenter stays null because a detached control has no
	// presenter to look up. This is why the availability source claims neither variable - the walked
	// facts record no createTemplate() argument, and 20 of this corpus's 34 factory call sites pass
	// none. The row exists to pin the vendor half of that follow-up.
	public function testControlIsWrittenOnlyWhenCreateTemplateReceivesOne(): void
	{
		$control = new class extends Control {

		};

		$parameters = $this->factory()->createTemplate($control)->getParameters();

		self::assertSame($control, $parameters['control']);
		self::assertNull($parameters['presenter']);
	}

	// $flashes is the one key the factory's own value is never null for: no presenter means no flash
	// session, and the fallback is [] rather than null.
	public function testFlashesIsAlwaysWrittenBecauseItsValueIsNeverNull(): void
	{
		self::assertSame([], $this->factory()->createTemplate()->getParameters()['flashes']);
	}

	// THE property_exists GATE, at runtime: a template class declaring no $flashes never receives
	// one, even though the factory's value for it is non-null and unconditional.
	public function testUndeclaredKeyIsNeverInjected(): void
	{
		$parameters = $this->factory()->createTemplate(null, ParityFlashlessTemplate::class)->getParameters();

		self::assertFalse(array_key_exists('flashes', $parameters));
		self::assertSame(['declared'], $this->sortedKeys($parameters));
	}

	// A template built WITHOUT the factory still hands its body every untyped declared property as a
	// parameter - the reason the availability source needs no creation-channel gate for a variable it
	// models as nullable: manual construction and a skipped injection are the same thing to the body.
	public function testManuallyConstructedTemplateStillExportsEveryDeclaredProperty(): void
	{
		$parameters = (new DefaultTemplate(new Engine()))->getParameters();

		self::assertSame(
			['basePath', 'baseUrl', 'control', 'flashes', 'presenter', 'user'],
			$this->sortedKeys($parameters),
		);
		self::assertNull($parameters['user']);
		// ... and the one key that survives it is the one carrying a non-null property default,
		// which is exactly the condition FactoryProvidedVars::certaintyOf() requires before calling
		// a variable definitely present.
		self::assertSame([], $parameters['flashes']);
	}

	// THE VERSION DIVERGENCE, pinned. v3.1.15 gates injection on property_exists() ALONE: it writes
	// the value into a declared property whose type cannot hold it, and PHP throws. Newer
	// nette/application versions add a type-compatibility check that SKIPS such a property instead,
	// and this test goes red on that upgrade - the signal to add the type condition as a second
	// clause of the gate (FactoryProvidedVars' own property_exists half). Modelling the check today
	// would report a variable as unavailable that this installed vendor demonstrably writes, which
	// is a false variable.undefined on correct code.
	public function testInjectionIgnoresPropertyTypeCompatibility(): void
	{
		$this->expectException(TypeError::class);

		$this->factory()->createTemplate(null, ParityTypedFlashesTemplate::class);
	}

	// THE WIRING READ's own vendor dependency: whether $user/$baseUrl/$basePath are definitely
	// present is decided by reading TemplateFactory's PRIVATE $user and $httpRequest out of the
	// compiled container's instance. A vendor rename would degrade that read to "not wired" - the
	// conservative direction, but silently, turning every one of those variables back into a
	// nullable one across the whole corpus. Pinned here so the rename breaks a test instead.
	public function testFactoryKeepsThePrivateDependencyNamesTheWiringReadDependsOn(): void
	{
		foreach (
			[
				TemplateFactoryDefaultResolver::KEY_USER,
				TemplateFactoryDefaultResolver::KEY_HTTP_REQUEST,
			] as $name
		) {
			self::assertTrue(property_exists(TemplateFactory::class, $name), $name);
		}
	}

	// ... and that reading them back really does report what the container supplied, against the
	// installed vendor's own constructor rather than a stand-in.
	public function testSuppliedDependenciesAreReadableBackOffTheFactoryInstance(): void
	{
		$user = new class extends User {

			public function __construct()
			{
			}

		};
		$factory = new TemplateFactory(new FixtureBridgeLatteFactory(), new Request(new UrlScript('http://x/')), $user);

		self::assertSame($user, $this->readPrivate($factory, TemplateFactoryDefaultResolver::KEY_USER));
		self::assertInstanceOf(
			Request::class,
			$this->readPrivate($factory, TemplateFactoryDefaultResolver::KEY_HTTP_REQUEST),
		);
		self::assertNull($this->readPrivate($this->factory(), TemplateFactoryDefaultResolver::KEY_USER));
	}

	/**
	 * @return mixed
	 */
	private function readPrivate(TemplateFactory $factory, string $name)
	{
		$property = new ReflectionProperty(TemplateFactory::class, $name);
		$property->setAccessible(true);

		return $property->getValue($factory);
	}

	private function factory(): TemplateFactory
	{
		return new TemplateFactory(new FixtureBridgeLatteFactory());
	}

	/**
	 * @param array<string, mixed> $parameters
	 * @return list<string>
	 */
	private function sortedKeys(array $parameters): array
	{
		$keys = array_keys($parameters);
		sort($keys);

		return $keys;
	}

}
