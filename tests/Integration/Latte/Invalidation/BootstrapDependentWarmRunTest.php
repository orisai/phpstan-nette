<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Invalidation;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\FixtureBridgeLatteFactory;
use function dirname;
use function implode;
use function str_replace;

// ResultCacheManager::restore() values every tracked key in the coordinator before %bootstrapFiles%
// run, the workers value them after. The container here creates its template factory with a
// request class only the bootstrap autoloader loads, as an application's container does with its
// own classes, so a value read off the live factory differs between the two unless the reader runs
// the bootstrap files first - and then every warm run re-analyses every template and every renderer.
final class BootstrapDependentWarmRunTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/../Integration/Fixtures/integration.neon';

	public function testWarmRunOnAnUnchangedTreeAnalysesNothing(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scenario = InvalidationScenario::create(
			$projectRoot,
			'latte-bootstrap-warm',
			static fn (array $paths, string $tmpDir): string => LattePhpstanConfig::create(
				self::REAL_CONFIG_PATH,
				$paths,
				$tmpDir,
				[
					'orisai.nette.dic.containerLoader' => dirname($paths[0]) . '/loader.php',
					'orisai.nette.latte.templateFactoryContainerLoader' => dirname($paths[0]) . '/loader.php',
					'orisai.nette.latte.discovery.enabled' => true,
					'orisai.nette.latte.discovery.storePath' => $paths[0] . '/discovery',
					'orisai.nette.latte.firstPartyPaths' => [$paths[0]],
				],
				[dirname($paths[0]) . '/bootstrap.php'],
			)->getConfigPath(),
		)->spawnWorkers();

		try {
			$this->seed($scenario, $projectRoot);

			$cold = $scenario->run();
			self::assertFalse($cold->isCacheRestored(), $cold->getDiagnostics());

			$warm = $scenario->run();
			self::assertSame(
				0,
				$warm->getReanalysedFiles(),
				'a warm run on an unchanged tree must re-analyse nothing: ' . implode(', ', $warm->getAnalysedFiles())
				. "\n" . $warm->getDiagnostics(),
			);
			self::assertSame([], $warm->getAnalysedFiles());
			self::assertSame($cold->getErrorText(), $warm->getErrorText());

			$scenario->write('ScratchFormControl.php', self::formControlSource('$form->addText(\'name\');' . "\n"));

			$edited = $scenario->run();
			self::assertTrue($edited->isCacheRestored(), $edited->getDiagnostics());
			self::assertContains('ScratchFormControl.php', $edited->getAnalysedFiles());
			self::assertNotContains('ScratchConsumer.php', $edited->getAnalysedFiles());
		} finally {
			$scenario->cleanup();
		}
	}

	private function seed(InvalidationScenario $scenario, string $projectRoot): void
	{
		$scratchDir = $scenario->getScratchDir();

		FileSystem::write($scratchDir . '/bootstrap.php', str_replace('{{ROOT}}', $projectRoot, <<<'PHP'
<?php declare(strict_types = 1);

require_once '{{ROOT}}/tests/autoload.php';

spl_autoload_register(static function (string $class): void {
	foreach ([__DIR__ . '/src/', __DIR__ . '/lib/'] as $dir) {
		if (is_file($dir . $class . '.php')) {
			require_once $dir . $class . '.php';

			return;
		}
	}
});

PHP));

		FileSystem::write($scratchDir . '/lib/ScratchRequest.php', <<<'PHP'
<?php declare(strict_types = 1);

final class ScratchRequest extends \Nette\Http\Request
{

}

PHP);

		FileSystem::write($scratchDir . '/loader.php', str_replace(
			['{{ROOT}}', '{{LATTE_FACTORY}}'],
			[$projectRoot, '\\' . FixtureBridgeLatteFactory::class],
			<<<'PHP'
<?php declare(strict_types = 1);

require_once '{{ROOT}}/tests/autoload.php';

return [
	'default' => new class extends \Nette\DI\Container {

		public function getByType(string $type, bool $throw = true): ?object
		{
			if ($type !== \Nette\Application\UI\TemplateFactory::class) {
				return null;
			}

			return \Tests\OriPhpstan\Nette\Toolkit\TemplateFactories::create(
				new {{LATTE_FACTORY}}(),
				new \ScratchRequest(new \Nette\Http\UrlScript('http://localhost/')),
			);
		}

	},
];

PHP,
		));

		$scenario->write('ScratchFormControl.php', self::formControlSource(''));
		$scenario->write('ScratchConsumer.php', <<<'PHP'
<?php declare(strict_types = 1);

final class ScratchConsumer
{

	public function lookup(\Nette\DI\Container $container): ?ScratchFormControl
	{
		return $container->getByType(ScratchFormControl::class, false);
	}

}

PHP);
		$scenario->write('scratch.latte', "{control form}\n");

		DiscoveryStore::bootstrap(
			$scenario->getSourceDir() . '/discovery',
			[$scenario->projectRelative('scratch.latte')],
		);
	}

	private static function formControlSource(string $extraField): string
	{
		return <<<PHP
<?php declare(strict_types = 1);

use Nette\Application\UI\Control;
use Nette\Application\UI\Form;

final class ScratchFormControl extends Control
{

	public function render(): void
	{
		\$this->getTemplate()->setFile(__DIR__ . '/scratch.latte');
	}

	public function name(): string
	{
		\$values = \$this['form']->getValues('array');

		return \$values['email'];
	}

	protected function createComponentForm(): Form
	{
		\$form = new Form();
		\$form->addText('email');
		$extraField
		return \$form;
	}

}

PHP;
	}

}
