<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge;

use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryResolver;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderFacts;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderWalk;
use OriPhpstan\Nette\Latte\Bridge\TemplateFactoryDefaultResolver;
use PHPStan\Parser\Parser;
use PHPStan\Testing\PHPStanTestCase;
use ReflectionClass;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\Filters\DescriptionTrait;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\Filters\FilterBasePresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\Filters\FilterHelpers;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\Filters\FilterPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\Filters\FirstClassCallableControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\Filters\LoaderFilterControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\Filters\OpenFilterControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\Filters\RenderFileControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\Filters\UnrelatedFilterControl;
use function array_keys;
use function basename;
use function dirname;
use function realpath;
use function sort;

final class PhpRenderWalkFilterTest extends PHPStanTestCase
{

	use VersionGroupGate;

	private const Helpers = FilterHelpers::class;

	public function testEveryCallableShapeIsRecordedWithItsTarget(): void
	{
		$facts = $this->walk()->factsFor(FilterPresenter::class);

		$presenter = FilterPresenter::class;
		self::assertSame(
			[
				'arrow' => ['stub', 'static function (int $i): int { /** @var int $ʟ_filterResult */ $ʟ_filterResult = \OriPhpstan\Nette\Latte\Runtime\Helpers::untypedFilter(); return $ʟ_filterResult; }', false, Certainty::HAPPENS],
				'aware' => ['static', self::Helpers . '::aware', true, Certainty::HAPPENS],
				'email' => ['static', self::Helpers . '::email', false, Certainty::HAPPENS],
				'fmt' => ['instance', self::Helpers . '::format', false, Certainty::HAPPENS],
				'formatDescription' => ['instance', $presenter . '::formatDescription', false, Certainty::HAPPENS],
				'instanceAsStatic' => ['untyped', '', false, Certainty::HAPPENS],
				'invokable' => ['instance', self::Helpers . '::__invoke', false, Certainty::HAPPENS],
				'isSlideType' => ['stub', 'static function (int $type): \Nette\Utils\Html { /** @var \Nette\Utils\Html $ʟ_filterResult */ $ʟ_filterResult = \OriPhpstan\Nette\Latte\Runtime\Helpers::untypedFilter(); return $ʟ_filterResult; }', false, Certainty::HAPPENS],
				'maybe' => ['function', 'strtoupper', false, Certainty::MAYBE],
				'missingMethod' => ['untyped', '', false, Certainty::HAPPENS],
				'phone' => ['static', self::Helpers . '::email', false, Certainty::HAPPENS],
				'secret' => ['stub', 'static function (int $i, string ...$rest): ?\\' . $presenter . ' { /** @var ?\\' . $presenter . ' $ʟ_filterResult */ $ʟ_filterResult = \OriPhpstan\Nette\Latte\Runtime\Helpers::untypedFilter(); return $ʟ_filterResult; }', false, Certainty::HAPPENS],
				'twice' => ['untyped', '', false, Certainty::HAPPENS],
				'unresolvable' => ['untyped', '', false, Certainty::HAPPENS],
				'untypedArrow' => ['stub', 'static function ($i) { return \OriPhpstan\Nette\Latte\Runtime\Helpers::untypedFilter(); }', false, Certainty::HAPPENS],
				'visibleForRole' => ['stub', 'static function (?string $disableFor, int $level = \\' . FilterBasePresenter::class . '::LEVEL): string { /** @var string $ʟ_filterResult */ $ʟ_filterResult = \OriPhpstan\Nette\Latte\Runtime\Helpers::untypedFilter(); return $ʟ_filterResult; }', false, Certainty::HAPPENS],
			],
			self::describe($facts),
		);
		self::assertFalse($facts->hasOpenFilterSet());
	}

	public function testTheCallablesDeclaringFilesJoinTheReadSet(): void
	{
		$readSet = $this->walk()->factsFor(FilterPresenter::class)->getReadSet();

		self::assertContains(self::fileOf(FilterHelpers::class), $readSet);
		self::assertContains(self::fileOf(DescriptionTrait::class), $readSet);
	}

	public function testDynamicNameOpensTheFilterSet(): void
	{
		$facts = $this->walk()->factsFor(OpenFilterControl::class);

		self::assertSame([], $facts->getFilters());
		self::assertTrue($facts->hasOpenFilterSet());
	}

	public function testFilterLoaderOpensTheFilterSet(): void
	{
		$facts = $this->walk()->factsFor(LoaderFilterControl::class);

		self::assertSame([], $facts->getFilters());
		self::assertTrue($facts->hasOpenFilterSet());
	}

	public function testUnrelatedReceiverRegistersNothing(): void
	{
		$facts = $this->walk()->factsFor(UnrelatedFilterControl::class);

		self::assertSame([], $facts->getFilters());
		self::assertFalse($facts->hasOpenFilterSet());
	}

	/**
	 * @requires PHP >= 8.1
	 */
	public function testFirstClassCallables(): void
	{
		$facts = $this->walk()->factsFor(FirstClassCallableControl::class);

		self::assertSame(
			[
				'function' => ['function', 'strtoupper', false, Certainty::HAPPENS],
				'instance' => ['instance', self::Helpers . '::format', false, Certainty::HAPPENS],
				'own' => ['stub', 'static function (int $i): string { /** @var string $ʟ_filterResult */ $ʟ_filterResult = \OriPhpstan\Nette\Latte\Runtime\Helpers::untypedFilter(); return $ʟ_filterResult; }', false, Certainty::HAPPENS],
				'static' => ['static', self::Helpers . '::email', false, Certainty::HAPPENS],
			],
			self::describe($facts),
		);
	}

	public function testRenderWithAFileLinksItLikeSetFile(): void
	{
		$facts = $this->walk()->factsFor(RenderFileControl::class);
		$discovery = $facts->getDiscovery();
		self::assertNotNull($discovery);

		$linked = [];
		foreach ($discovery->getViewCandidates() as $candidates) {
			foreach ($candidates as $candidate) {
				$linked[] = $candidate->getKind() . ' ' . basename($candidate->getPath());
			}
		}

		sort($linked);
		self::assertSame(['setFile fragment.latte', 'setFile rendered.latte'], $linked);
		self::assertSame([], $facts->getSetFileTargets());
		self::assertSame(['count', 'title'], array_keys($facts->getAssignments()));
		self::assertCount(2, $facts->getRenderSites());
	}

	public function testFiltersSurviveTheArrayRoundTrip(): void
	{
		$facts = $this->walk()->factsFor(FilterPresenter::class);

		self::assertSame($facts->toArray(), PhpRenderFacts::fromArray($facts->toArray())->toArray());
	}

	/**
	 * @return array<string, array{string, string, bool, string}>
	 */
	private static function describe(PhpRenderFacts $facts): array
	{
		$described = [];
		foreach ($facts->getFilters() as $name => $filter) {
			self::assertSame($name, $filter->getName());
			self::assertNotSame([], $filter->getSites());
			$described[$name] = [$filter->getKind(), $filter->getTarget(), $filter->isContentAware(), $filter->getCertainty()];
		}

		return $described;
	}

	private function walk(): PhpRenderWalk
	{
		$appRoot = realpath(__DIR__ . '/Fixtures/App');
		self::assertNotFalse($appRoot);

		/** @var Parser $parser */
		$parser = self::getContainer()->getService('currentPhpVersionRichParser');

		return new PhpRenderWalk(
			self::createReflectionProvider(),
			$parser,
			[$appRoot],
			new TemplateFactoryDefaultResolver(null),
			new DiscoveryResolver(null, [], dirname($appRoot)),
		);
	}

	/**
	 * @param class-string $className
	 */
	private static function fileOf(string $className): string
	{
		$file = (new ReflectionClass($className))->getFileName();
		self::assertIsString($file);

		return $file;
	}

}
