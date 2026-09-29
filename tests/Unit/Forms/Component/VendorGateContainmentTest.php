<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component;

use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\IsolatedPhpstanConfig;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function dirname;
use function preg_match;
use function trim;
use const PHP_BINARY;

/**
 * ContainerModel::vendorMethodFormShape/vendorParentCallbackShape must draw the project/vendor
 * line from the config's declared paths (`%paths%`), not PHPStan's CLI-narrowed
 * `%analysedPaths%`. BaseControl.php declares createComponentForm() (a 'note' field) and an
 * abstract formSucceeded() handler; ChildControl.php (the only CLI-listed file here) implements
 * formSucceeded() and reads `$form->getComponent('note')`. BaseControl.php is never CLI-listed by
 * this single-file run but IS under the config's declared paths (Fixtures/VendorGate), so a full
 * run analysing both files never treats it as vendor either — confirmed separately: a full run
 * over both fixtures dumps `Nette\ComponentModel\IComponent` (the containerModel gate refuses,
 * and nothing else in the chain resolves this cross-class handler-origin pattern).
 *
 * A CLI-narrowed gate wrongly treats the non-CLI-listed BaseControl.php as vendor and resolves
 * 'note' through the crude AST fallback (vendorParentCallbackShape), giving a MORE precise answer
 * than a full run ever confirms (`Nette\Forms\Controls\TextInput` instead of the full run's
 * `Nette\ComponentModel\IComponent`) — a single-file/IDE-only false precision a project's own
 * `make phpstan` would never back up. The config-scoped gate refuses identically to a full run.
 */
final class VendorGateContainmentTest extends BaseTestCase
{

	public function testSingleFileRunDoesNotResolveANonCliListedAncestorMoreCloselyThanAFullRun(): void
	{
		$file = __DIR__ . '/Fixtures/VendorGate/ChildControl.php';
		$isolated = IsolatedPhpstanConfig::create(
			__DIR__ . '/component-real.neon',
			[__DIR__ . '/Fixtures/VendorGate'],
		);

		try {
			$dumped = $this->dumpedTypeFor($isolated->getConfigPath(), $file);

			self::assertSame(
				'Nette\ComponentModel\IComponent',
				$dumped,
				"a single-file run must not resolve BaseControl's createComponentForm (never CLI-listed "
					. 'here) any more precisely than a full run does — BaseControl.php is a project file under '
					. 'the config paths, not vendor code',
			);
		} finally {
			$isolated->cleanup();
		}
	}

	private function dumpedTypeFor(string $configFile, string $file): ?string
	{
		$root = dirname(__DIR__, 4);
		$process = new Process(
			[
				PHP_BINARY,
				$root . '/' . VendorDirectory::name() . '/bin/phpstan',
				'analyse',
				$file,
				'-c',
				$configFile,
				'--error-format=raw',
				'--no-progress',
				'--memory-limit=2048M',
			],
			$root,
		);
		$process->setTimeout(600.0);
		$process->run();
		$out = $process->getOutput() . $process->getErrorOutput();

		return preg_match('~Dumped type:\s*(.+)$~m', $out, $m) === 1 ? trim($m[1]) : null;
	}

}
