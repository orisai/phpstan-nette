<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Invalidation;

use Nette\Utils\FileSystem;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use function dirname;
use function strpos;

// orisaiNette.latte.firstPartyPaths is a config value, not a file: PhpRenderWalk's app-root gate
// reads it for every class, and the persisted render facts live under %tmpDir%, where a parameter
// change does not reach them. The facts envelope therefore carries the normalised value and a flip
// between two spawns over a byte-identical corpus has to recompute - the stale envelope would keep
// answering "qualifies" for a class the new boundary excludes.
final class FirstPartyPathsInvalidationTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/../Integration/Fixtures/integration.neon';

	private const TEMPLATE = 'ScratchBoundaryTemplate';

	private const CONTROL = 'ScratchBoundaryControl';

	private const NOT_QUALIFYING = 'no render facts: class does not qualify';

	/** @var list<string>|null */
	private ?array $firstPartyPaths = null;

	public function testFlippingFirstPartyPathsRecomputesTheRenderFacts(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scenario = InvalidationScenario::create(
			$projectRoot,
			'latte-inval-first-party',
			fn (array $paths, string $tmpDir): string => LattePhpstanConfig::create(
				self::REAL_CONFIG_PATH,
				$paths,
				$tmpDir,
				[
					'orisaiNette.latte.discovery.enabled' => false,
					'orisaiNette.latte.firstPartyPaths' => $this->firstPartyPaths ?? [$paths[0]],
				],
				[dirname($paths[0]) . '/corpus-autoload.php'],
			)->getConfigPath(),
		);

		try {
			$this->seed($scenario);

			$inside = $scenario->run();
			self::assertStringContainsString('orisaiNette.latte.debugDump', $inside->getErrorText());
			self::assertStringContainsString('template class: ', $inside->getErrorText());
			self::assertStringContainsString(self::TEMPLATE . ' (convention, happens)', $inside->getErrorText());
			self::assertFalse(strpos($inside->getErrorText(), self::NOT_QUALIFYING), $inside->getErrorText());

			// Same corpus, same tmpDir, the class now outside the boundary: the walk must not qualify
			// it, so the envelope written by the first run has to read stale.
			$this->firstPartyPaths = [$scenario->getSourceDir() . '/elsewhere'];
			$outside = $scenario->run();
			self::assertStringContainsString(self::NOT_QUALIFYING, $outside->getErrorText());
			self::assertFalse(strpos($outside->getErrorText(), 'template class:'), $outside->getErrorText());

			$this->firstPartyPaths = null;
			$back = $scenario->run();
			self::assertSame($inside->getErrorText(), $back->getErrorText());
		} finally {
			$scenario->cleanup();
		}
	}

	private function seed(InvalidationScenario $scenario): void
	{
		$autoloader = dirname(__DIR__, 4) . '/vendor/autoload.php';
		FileSystem::write(
			$scenario->getScratchDir() . '/corpus-autoload.php',
			<<<PHP
<?php declare(strict_types = 1);

require_once '$autoloader';

spl_autoload_register(static function (string \$class): void {
	\$file = __DIR__ . '/src/' . \$class . '.php';
	if (is_file(\$file)) {
		require_once \$file;
	}
});

PHP,
		);

		$template = self::TEMPLATE;
		$control = self::CONTROL;
		$scenario->write(self::TEMPLATE . '.php', <<<PHP
<?php declare(strict_types = 1);

class $template extends \Nette\Bridges\ApplicationLatte\Template
{

}

PHP);
		$scenario->write(self::CONTROL . '.php', <<<PHP
<?php declare(strict_types = 1);

class $control extends \Nette\Application\UI\Control
{

	public function formatTemplateClass(): ?string
	{
		return $template::class;
	}

	public function render(): void
	{
		\$this->getTemplate()->setFile(__DIR__ . '/boundary.latte');
	}

}

PHP);
		$scenario->write('boundary.latte', '{templateType ' . self::TEMPLATE . "}\n<p>boundary</p>\n");
		$scenario->write('ScratchBoundaryProbe.php', <<<PHP
<?php declare(strict_types = 1);

final class ScratchBoundaryProbe
{

	public function go(): void
	{
		\OriPhpstan\Nette\Latte\Testing\dumpLatteRenderFacts(\\$control::class);
	}

}

PHP);
	}

}
