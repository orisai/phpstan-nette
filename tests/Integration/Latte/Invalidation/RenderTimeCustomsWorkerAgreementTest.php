<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Invalidation;

use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use function dirname;
use function implode;

// Render-time filters and functions are read off render facts the coordinator values before the
// bootstrap files run and every spawned worker computes afresh while it analyses a template: a single
// process and spawned workers must find the same, and a warm run with workers must re-analyse nothing.
final class RenderTimeCustomsWorkerAgreementTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/../Integration/Fixtures/integration.neon';

	private const EXPECTED = [
		'catalog.latte:2 :: argument.type :: Parameter #1 $cents of static method ScratchPrices::format() expects int, string given.',
		'catalog.latte:3 :: argument.type :: Parameter #1 $percent of closure expects int, string given.',
		"catalog.latte:4 :: orisai.nette.latte.possiblyUnknownFilter :: Latte filter 'unseen' might not exist - its renderer"
			. ' ScratchCatalogControl registers filters with a dynamic name (ScratchCatalogControl.php:14), so the filters of'
			. ' this template are not known completely.',
		'row.latte:1 :: argument.type :: Parameter #1 $cents of static method ScratchPrices::format() expects int, string given.',
		'row.latte:2 :: argument.type :: Parameter #1 $value of closure expects int, string given.',
	];

	public function testSingleProcessAndSpawnedWorkersAgree(): void
	{
		$single = $this->scenario('latte-render-time-single', 1)->singleProcess();
		$workers = $this->scenario('latte-render-time-workers', 4)->spawnWorkers();

		try {
			$this->seed($single);
			$this->seed($workers);

			$singleRun = $single->run();
			self::assertSame(implode("\n", self::EXPECTED), $singleRun->getErrorText(), $singleRun->getDiagnostics());

			$cold = $workers->run();
			self::assertFalse($cold->isCacheRestored(), $cold->getDiagnostics());
			self::assertSame($singleRun->getErrorText(), $cold->getErrorText(), $cold->getDiagnostics());

			$warm = $workers->run();
			self::assertSame(
				0,
				$warm->getReanalysedFiles(),
				'a warm run on an unchanged tree must re-analyse nothing: ' . implode(', ', $warm->getAnalysedFiles())
				. "\n" . $warm->getDiagnostics(),
			);
			self::assertSame($singleRun->getErrorText(), $warm->getErrorText());
		} finally {
			$single->cleanup();
			$workers->cleanup();
		}
	}

	private function scenario(string $name, int $processes): InvalidationScenario
	{
		return InvalidationScenario::create(
			dirname(__DIR__, 4),
			$name,
			static fn (array $paths, string $tmpDir): string => LattePhpstanConfig::create(
				self::REAL_CONFIG_PATH,
				$paths,
				$tmpDir,
				[
					'orisai.nette.latte.discovery.enabled' => true,
					'orisai.nette.latte.discovery.storePath' => $paths[0] . '/discovery',
					'orisai.nette.latte.firstPartyPaths' => [$paths[0]],
					'parallel.jobSize' => 1,
					'parallel.maximumNumberOfProcesses' => $processes,
					'parallel.minimumNumberOfJobsPerProcess' => 1,
				],
			)->getConfigPath(),
		);
	}

	private function seed(InvalidationScenario $scenario): void
	{
		$scenario->write('ScratchPrices.php', <<<'PHP'
<?php declare(strict_types = 1);

final class ScratchPrices
{

	public static function format(int $cents): string
	{
		return (string) $cents;
	}

}

PHP);
		$scenario->write('ScratchCatalogControl.php', <<<'PHP'
<?php declare(strict_types = 1);

/**
 * @property-read Nette\Bridges\ApplicationLatte\DefaultTemplate $template
 */
final class ScratchCatalogControl extends Nette\Application\UI\Control
{

	public function render(string $extra): void
	{
		$this->template->addFilter('price', [ScratchPrices::class, 'format']);
		$this->template->getLatte()->addFunction('discount', static fn (int $percent): float => $percent / 100);
		$this->template->addFunction('double', static fn (int $value): int => $value * 2);
		$this->template->addFilter($extra, 'strtoupper');
		$this->template->render(__DIR__ . '/catalog.latte');
	}

}

PHP);
		$scenario->write(
			'catalog.latte',
			"{=100|price}\n{='x'|price}\n{=discount('x')}\n{='x'|unseen}\n{include 'row.latte'}\n",
		);
		$scenario->write('row.latte', "{='y'|price}\n{=double('z')}\n");

		DiscoveryStore::bootstrap(
			$scenario->getSourceDir() . '/discovery',
			[$scenario->projectRelative('catalog.latte'), $scenario->projectRelative('row.latte')],
		);
	}

}
