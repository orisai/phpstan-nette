<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge;

use Nette\Application\UI\Template as UiTemplate;
use Nette\Bridges\ApplicationLatte\DefaultTemplate;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryResolver;
use OriPhpstan\Nette\Latte\Bridge\Pairing\PairingJudge;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderFacts;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderWalk;
use OriPhpstan\Nette\Latte\Bridge\TemplateClassFact;
use OriPhpstan\Nette\Latte\Bridge\TemplateFactoryDefaultResolver;
use PHPStan\Parser\Parser;
use PHPStan\Testing\PHPStanTestCase;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DefaultTemplateSurfaceDescendantControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\SurfaceFloorControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\SurfaceFloorPresenter;
use function dirname;
use function realpath;

final class TemplateSurfaceFloorTest extends PHPStanTestCase
{

	use VersionGroupGate;

	/**
	 * @return iterable<string, array{class-string}>
	 */
	public static function provideVendorSurfaceOnlyClasses(): iterable
	{
		yield 'control' => [SurfaceFloorControl::class];
		yield 'presenter' => [SurfaceFloorPresenter::class];
	}

	/**
	 * @param class-string $className
	 *
	 * @dataProvider provideVendorSurfaceOnlyClasses
	 */
	public function testVendorDeclaredSurfaceIsAFloorNotABinding(string $className): void
	{
		$facts = $this->factsFor($className);

		self::assertSame([], $facts->getTemplateClassCandidates());

		$templateClass = $facts->getTemplateClass();
		self::assertNotNull($templateClass);
		self::assertSame(UiTemplate::class, $templateClass->getClassName());
		self::assertSame(TemplateClassFact::CHANNEL_TEMPLATE_FLOOR, $templateClass->getChannel());

		$verdict = (new PairingJudge(self::createReflectionProvider()))->judge($facts);
		self::assertSame(UiTemplate::class, $verdict->getPrimaryClass());
		self::assertSame(TemplateClassFact::CHANNEL_TEMPLATE_FLOOR, $verdict->getPrimaryChannel());
		self::assertSame([], $verdict->getConflicts());
		self::assertSame([], $verdict->getOpaques());
	}

	public function testNonVendorDeclaredSurfaceStaysABinding(): void
	{
		$facts = $this->factsFor(DefaultTemplateSurfaceDescendantControl::class);

		self::assertEquals(
			[new TemplateClassFact(
				DefaultTemplate::class,
				TemplateClassFact::CHANNEL_GENERIC_BINDING,
				Certainty::HAPPENS,
			)],
			$facts->getTemplateClassCandidates(),
		);

		$templateClass = $facts->getTemplateClass();
		self::assertNotNull($templateClass);
		self::assertSame(DefaultTemplate::class, $templateClass->getClassName());
		self::assertSame(TemplateClassFact::CHANNEL_GENERIC_BINDING, $templateClass->getChannel());
	}

	private function factsFor(string $className): PhpRenderFacts
	{
		$appRoot = realpath(__DIR__ . '/Fixtures/App');
		self::assertNotFalse($appRoot);

		/** @var Parser $parser */
		$parser = self::getContainer()->getService('currentPhpVersionRichParser');

		$walk = new PhpRenderWalk(
			self::createReflectionProvider(),
			$parser,
			[$appRoot],
			new TemplateFactoryDefaultResolver(null),
			new DiscoveryResolver(null, [], dirname($appRoot)),
		);

		return $walk->factsFor($className);
	}

}
