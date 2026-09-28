<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Rule;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryResolver;
use OriPhpstan\Nette\Latte\Bridge\Pairing\PairingJudge;
use OriPhpstan\Nette\Latte\Bridge\PhpFactsCache;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderWalk;
use OriPhpstan\Nette\Latte\Bridge\TemplateFactoryDefaultResolver;
use OriPhpstan\Nette\Latte\Cache\LatteAnalysisCache;
use OriPhpstan\Nette\Latte\Rule\LattePairingRule;
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
 * @extends RuleTestCase<LattePairingRule>
 */
final class LattePairingRuleDisabledTest extends RuleTestCase
{

	private const FixtureDir = __DIR__ . '/../Bridge/Pairing/Fixtures/App';

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

		/** @var Parser $parser */
		$parser = self::getContainer()->getService('currentPhpVersionRichParser');
		$reflectionProvider = self::createReflectionProvider();
		$factoryDefault = new TemplateFactoryDefaultResolver(null);

		return new LattePairingRule(
			TestGuard::latte(false),
			new PhpRenderWalk(
				$reflectionProvider,
				$parser,
				[$appRoot],
				$factoryDefault,
				new DiscoveryResolver(null, [], $appRoot),
			),
			new PhpFactsCache(
				new LatteAnalysisCache($this->isolatedCacheDir()),
				$factoryDefault,
				new DiscoveryResolver(null, [], $appRoot),
				[],
			),
			new PairingJudge($reflectionProvider),
		);
	}

	/**
	 * @return list<string>
	 */
	public static function getAdditionalConfigFiles(): array
	{
		return [__DIR__ . '/phpstan-test.neon'];
	}

	// Dormancy pin (Task-4 review I1): orisaiNette.latte.enabled off means no findings AND no walk/cache
	// cost - the conflict fixture that fires in LattePairingRuleTest stays silent, and the cache
	// directory is never even created (the short-circuit precedes remember()).
	public function testFlagOffIsFullyDormantOnAConflictFixture(): void
	{
		$this->analyse([self::FixtureDir . '/PairingNarrowerDeclarationPresenter.php'], []);

		self::assertNotNull($this->cacheDir);
		self::assertDirectoryDoesNotExist($this->cacheDir);
	}

	private function isolatedCacheDir(): string
	{
		return $this->cacheDir ??= sys_get_temp_dir() . '/latte-pairing-rule-disabled-' . getmypid() . '-' . uniqid(
			'',
			true,
		);
	}

}
