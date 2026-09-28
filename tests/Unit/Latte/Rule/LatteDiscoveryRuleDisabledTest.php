<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Rule;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryResolver;
use OriPhpstan\Nette\Latte\Bridge\PhpFactsCache;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderWalk;
use OriPhpstan\Nette\Latte\Bridge\TemplateFactoryDefaultResolver;
use OriPhpstan\Nette\Latte\Cache\LatteAnalysisCache;
use OriPhpstan\Nette\Latte\Rule\LatteDiscoveryRule;
use PHPStan\Parser\Parser;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;
use function getmypid;
use function is_dir;
use function realpath;
use function sys_get_temp_dir;
use function uniqid;

/**
 * @extends RuleTestCase<LatteDiscoveryRule>
 */
final class LatteDiscoveryRuleDisabledTest extends RuleTestCase
{

	private const FixtureDir = __DIR__ . '/../Bridge/Fixtures/App';

	private const MappingLoaderFile = __DIR__ . '/../Bridge/Fixtures/presenter-mapping-container-loader.php';

	private ?string $cacheDir = null;

	protected function tearDown(): void
	{
		parent::tearDown();

		if ($this->cacheDir !== null && is_dir($this->cacheDir)) {
			FileSystem::delete($this->cacheDir);
		}

		$this->cacheDir = null;
	}

	protected function getRule(): Rule
	{
		$appRoot = realpath(self::FixtureDir);
		self::assertNotFalse($appRoot);
		$projectRoot = realpath(__DIR__ . '/../Bridge/Fixtures');
		self::assertNotFalse($projectRoot);

		/** @var Parser $parser */
		$parser = self::getContainer()->getService('currentPhpVersionRichParser');

		$factoryDefault = new TemplateFactoryDefaultResolver(null);
		$discoveryResolver = new DiscoveryResolver(self::MappingLoaderFile, [], $projectRoot);

		return new LatteDiscoveryRule(
			TestGuard::latte(false),
			new PhpRenderWalk(
				self::createReflectionProvider(),
				$parser,
				[$appRoot],
				$factoryDefault,
				$discoveryResolver,
			),
			new PhpFactsCache(
				new LatteAnalysisCache($this->isolatedCacheDir()),
				$factoryDefault,
				$discoveryResolver,
				[],
			),
		);
	}

	/**
	 * @return list<string>
	 */
	public static function getAdditionalConfigFiles(): array
	{
		return [__DIR__ . '/phpstan-test.neon'];
	}

	// Dormancy pin (the SP2 Task-4 lesson): orisaiNette.latte.enabled off means no findings AND no
	// walk/cache cost - both fixtures that fire in LatteDiscoveryRuleTest stay silent, and the cache
	// directory is never even created (the short-circuit precedes remember()).
	public function testFlagOffIsFullyDormantOnAnOpaqueFixture(): void
	{
		$this->analyse([self::FixtureDir . '/DiscoverySetFileOpaquePresenter.php'], []);

		self::assertNotNull($this->cacheDir);
		self::assertDirectoryDoesNotExist($this->cacheDir);
	}

	public function testFlagOffIsFullyDormantOnAnIneffectiveMutationFixture(): void
	{
		$this->analyse([self::FixtureDir . '/ShutdownOnlyViewPresenter.php'], []);

		self::assertNotNull($this->cacheDir);
		self::assertDirectoryDoesNotExist($this->cacheDir);
	}

	private function isolatedCacheDir(): string
	{
		return $this->cacheDir ??= sys_get_temp_dir() . '/latte-discovery-rule-disabled-' . getmypid() . '-' . uniqid(
			'',
			true,
		);
	}

}
