<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Latte\Bridge\Discovery\CandidatePath;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryRecordSource;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryResolver;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use OriPhpstan\Nette\Latte\Bridge\Pairing\PairingJudge;
use OriPhpstan\Nette\Latte\Bridge\PhpFactsCache;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderWalk;
use OriPhpstan\Nette\Latte\Bridge\TemplateFactoryDefaultResolver;
use OriPhpstan\Nette\Latte\Cache\LatteAnalysisCache;
use OriPhpstan\Nette\Latte\Includes\FactoryProvidedVars;
use PHPStan\Parser\Parser;
use PHPStan\Testing\PHPStanTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InstalledVersionsGuard;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsPresenterRenderer;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsTemplateVariableBasePresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsTemplateVariableControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsTemplateVariablePresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\FixtureTemplateTypeContainer;
use function array_map;
use function getmypid;
use function realpath;
use function sys_get_temp_dir;
use function uniqid;

final class TemplateVariableAttributeTest extends PHPStanTestCase
{

	use VersionGroupGate;

	private const AppFixtureDir = __DIR__ . '/Fixtures/App';

	private const TemplateRel = 'page.latte';

	protected function setUp(): void
	{
		parent::setUp();
		InstalledVersionsGuard::requireNetteLine('nette/application', '>=3.2');
	}

	public function testPresenterTemplateVariablesAreDefinitelyPresentWithTheirDeclaredTypes(): void
	{
		$types = $this->typesFor([FactoryVarsTemplateVariablePresenter::class]);

		self::assertSame('string', $types['title']);
		self::assertSame('list<int>', $types['ids']);
		self::assertSame('array', $types['crumbs']);
	}

	public function testOnlyPublicInstancePropertiesCarryingTheAttributeAreTemplateVariables(): void
	{
		$types = $this->typesFor([FactoryVarsTemplateVariablePresenter::class]);

		self::assertArrayNotHasKey('unmarked', $types);
		self::assertArrayNotHasKey('hidden', $types);
		self::assertArrayNotHasKey('shared', $types);
	}

	public function testTemplateVariablesAreDefinitelyPresentAndNamedByTheirDeclaringClass(): void
	{
		$vars = $this->resolveFor([FactoryVarsTemplateVariablePresenter::class]);

		self::assertSame(Certainty::HAPPENS, $vars['title']['certainty']);
		self::assertSame(FactoryVarsTemplateVariablePresenter::class, $vars['title']['class']);
		self::assertSame(FactoryVarsTemplateVariableBasePresenter::class, $vars['crumbs']['class']);
	}

	public function testControlTemplateVariablesAreNeverPassedToTheTemplate(): void
	{
		self::assertArrayNotHasKey('title', $this->typesFor([FactoryVarsTemplateVariableControl::class]));
	}

	public function testRendererWithoutTheVariableDropsItFromASharedTemplate(): void
	{
		$types = $this->typesFor([
			FactoryVarsTemplateVariablePresenter::class,
			FactoryVarsPresenterRenderer::class,
		]);

		self::assertArrayNotHasKey('title', $types);
		self::assertArrayHasKey('presenter', $types);
	}

	public function testDeclaringClassesJoinTheScopeClasses(): void
	{
		$dir = $this->scratchDir();

		try {
			self::assertContains(
				FactoryVarsTemplateVariableBasePresenter::class,
				$this->source($dir, [FactoryVarsTemplateVariablePresenter::class])->templateClassesFor(
					self::TemplateRel,
				),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @param list<string> $rendererClasses
	 * @return array<string, string>
	 */
	private function typesFor(array $rendererClasses): array
	{
		$dir = $this->scratchDir();

		try {
			return $this->source($dir, $rendererClasses)->typesFor(self::TemplateRel);
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @param list<string> $rendererClasses
	 * @return array<string, array{certainty: string, type: string, class: string}>
	 */
	private function resolveFor(array $rendererClasses): array
	{
		$dir = $this->scratchDir();

		try {
			return $this->source($dir, $rendererClasses)->resolveFor(self::TemplateRel);
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @param list<string> $rendererClasses
	 */
	private function source(string $dir, array $rendererClasses): FactoryProvidedVars
	{
		$templateFactoryDefault = new TemplateFactoryDefaultResolver(null);

		$store = new DiscoveryStore($dir . '/store');
		$store->replaceWith(
			[
				self::TemplateRel => array_map(
					static fn (string $className): array => [
						'class' => $className,
						'view' => 'default',
						'kind' => CandidatePath::KIND_FORMULA,
						'certainty' => Certainty::HAPPENS,
					],
					$rendererClasses,
				),
			],
			$rendererClasses,
			[],
		);

		return new FactoryProvidedVars(
			new FixtureTemplateTypeContainer(
				$this->recordSource($dir, $templateFactoryDefault),
				new PairingJudge(self::createReflectionProvider()),
				self::createReflectionProvider(),
			),
			$store,
			$templateFactoryDefault,
			true,
		);
	}

	private function recordSource(
		string $dir,
		TemplateFactoryDefaultResolver $templateFactoryDefault
	): DiscoveryRecordSource
	{
		/** @var Parser $parser */
		$parser = self::getContainer()->getService('currentPhpVersionRichParser');

		$appRoot = realpath(self::AppFixtureDir);
		self::assertNotFalse($appRoot);

		$discoveryResolver = new DiscoveryResolver(null, [], $appRoot);

		return new DiscoveryRecordSource(
			new PhpFactsCache(
				new LatteAnalysisCache($dir . '/cache'),
				$templateFactoryDefault,
				$discoveryResolver,
				[],
			),
			new PhpRenderWalk(
				self::createReflectionProvider(),
				$parser,
				[$appRoot],
				$templateFactoryDefault,
				$discoveryResolver,
			),
		);
	}

	private function scratchDir(): string
	{
		return sys_get_temp_dir() . '/latte-template-variable-' . getmypid() . '-' . uniqid('', true);
	}

}
