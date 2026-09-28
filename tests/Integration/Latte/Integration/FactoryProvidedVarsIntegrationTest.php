<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use function array_keys;
use function array_map;
use function dirname;
use function explode;
use function implode;
use function preg_replace;
use function rtrim;
use function str_replace;
use function strpos;
use function uniqid;
use const PHP_BINARY;

// End-to-end, against a real spawned phpstan: what the factory-provided scope actually does to the
// findings, which is the only measurement that settles it. Everything else in this feature's test
// suite pins the source's own answers; these rows pin that those answers reach the analyser and
// change its verdict, in both directions.
final class FactoryProvidedVarsIntegrationTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	private const WIRED_CONTAINER_LOADER = __DIR__ . '/../../../Unit/Latte/Includes/Fixtures/factory-vars-wired-container-loader.php';

	private const ORPHAN_MESSAGE = 'No analysable render, include or layout path reaches this template file.';

	// The createTemplate() override that bypasses the vendor body the component would otherwise
	// inherit, so the factory receives no control at all.
	private const STANDALONE_FACTORY_MEMBERS = "\tpublic Nette\\Bridges\\ApplicationLatte\\TemplateFactory \$templateFactory;\n\n"
		. "\tprotected function createTemplate(): Nette\\Application\\UI\\Template\n"
		. "\t{\n"
		. "\t\treturn \$this->templateFactory->createTemplate();\n"
		. "\t}\n\n";

	// The whole feature in one measurement, on the FIRST run: the pre-analysis index build links the
	// renderer to the template before anything is parsed, so the five claimed variables are already
	// clear - including the one used inside a {block}, which is a different generated method from the
	// template body. $control is not merely defined but DEFINITELY NON-NULL, which is why line 5 now
	// carries a strict-comparison finding instead of an undefined-variable one: the renderer is a
	// plain component on the inherited createTemplate(), so the factory really does receive it. Its
	// $presenter keeps being reported, because that one is the runtime attachment state. The type is
	// the template class's own declared Control rather than the renderer's class only because a
	// scratch fixture written outside the autoloader cannot be reflected by controlArgumentTypeOf()'s
	// is_a() - on real, autoloadable renderers the refinement lands (FactoryProvidedVarsTest pins
	// it). $undefinedVar keeps being reported throughout, which is what proves the run still reports
	// undefined variables at all.
	//
	// Run 2 asserts the same output for the reason DiscoveryStoreSelfSufficiencyTest exists: this
	// used to be the row where run 1 reported all eight variables and only run 2 the three, because
	// the store a run read was the PREVIOUS run's.
	public function testStoreLinkedTemplateStopsReportingTheFactoryVariables(): void
	{
		$this->withCorpus(function (string $projectRoot, string $srcDir, string $relSrc, string $storeDir): void {
			$tmpDir = dirname($srcDir) . '/pstmp';

			$expected = "$relSrc/tpl.latte:5:Strict comparison using === between "
				. "Nette\\Application\\UI\\Control and null will always evaluate to false.\n"
				. "$relSrc/tpl.latte:6:Undefined variable: \$presenter\n"
				. "$relSrc/tpl.latte:7:Undefined variable: \$undefinedVar\n";

			self::assertSame(
				$expected,
				$this->spawn($projectRoot, $srcDir, $tmpDir, $storeDir, null)['output'],
				'run 1 must already clear exactly the five claimed variables, off the index it built itself',
			);

			self::assertSame(
				$expected,
				$this->spawn($projectRoot, $srcDir, $tmpDir, $storeDir, null)['output'],
				'run 2 must find exactly what run 1 found',
			);
		});
	}

	// THE CONTROL AXIS end to end, against the row above, with the ONLY difference being the
	// renderer's createTemplate(): this one bypasses the vendor body and calls the factory
	// standalone, so no control reaches it and both axis variables are the untyped properties' own
	// implicit null - present, and nullable. $presenter therefore stops being reported here while
	// the identical template keeps reporting it above, and $control's line stops carrying the
	// always-false comparison it carries above, because a nullable $control really can be null. That
	// pair is the whole discrimination the recorded argument buys.
	public function testStandaloneFactoryRendererProvidesTheControlAxisAsNull(): void
	{
		$this->withCorpus(
			function (string $projectRoot, string $srcDir, string $relSrc, string $storeDir): void {
				$tmpDir = dirname($srcDir) . '/pstmp-standalone';
				$this->spawn($projectRoot, $srcDir, $tmpDir, $storeDir, null);

				self::assertSame(
					"$relSrc/tpl.latte:7:Undefined variable: \$undefinedVar\n",
					$this->spawn($projectRoot, $srcDir, $tmpDir, $storeDir, null)['output'],
					'a standalone factory call must still provide both axis variables, as the nulls they really are',
				);
			},
			null,
			true,
		);
	}

	// THE WIRING READ, end to end. With no container the scope's $user is nullable, so calling a
	// method on it is a nullability error; with a container that wires one, $user is definitely
	// present AND carries the wired class, so the same call type-checks and the wired subclass's own
	// members resolve. This is the direction that decides whether the feature subtracts findings or
	// trades one identifier for another.
	public function testWiredContainerRemovesTheNullabilityErrorTheUnwiredScopeProduces(): void
	{
		$this->withCorpus(
			function (string $projectRoot, string $srcDir, string $relSrc, string $storeDir): void {
				$unwiredTmp = dirname($srcDir) . '/pstmp-unwired';
				$this->spawn($projectRoot, $srcDir, $unwiredTmp, $storeDir, null);

				self::assertSame(
					"$relSrc/use.latte:1:Cannot call method isLoggedIn() on Nette\\Security\\User|null.\n"
					. "$relSrc/use.latte:2:Cannot call method fixtureOnlyMember() on Nette\\Security\\User|null.\n",
					$this->spawn($projectRoot, $srcDir, $unwiredTmp, $storeDir, null)['output'],
					'no container: the scope is honest about the vendor signature and reports the nullability',
				);

				$wiredTmp = dirname($srcDir) . '/pstmp-wired';
				$this->spawn($projectRoot, $srcDir, $wiredTmp, $storeDir, self::WIRED_CONTAINER_LOADER);

				self::assertSame(
					"\n",
					$this->spawn($projectRoot, $srcDir, $wiredTmp, $storeDir, self::WIRED_CONTAINER_LOADER)['output'],
					'wired container: $user is definitely present and carries the wired class, so both calls resolve',
				);
			},
			"{if \$user->isLoggedIn()}a{/if}\n{if \$user->fixtureOnlyMember()}b{/if}\n",
		);
	}

	/**
	 * @param callable(string, string, string, string): void $test
	 */
	private function withCorpus(
		callable $test,
		?string $useTemplate = null,
		bool $standaloneFactoryRenderer = false
	): void
	{
		$projectRoot = dirname(__DIR__, 4);
		// var/tmp/, never the system temp dir: ProjectRelativePath::relativize is a bare
		// str_replace($projectRoot . '/', '', $file), so a path outside $projectRoot never
		// relativizes and every rel-path lookup misses.
		$scratch = $projectRoot . '/var/tmp/latte-factory-vars-integration-' . uniqid('', true);
		$srcDir = $scratch . '/src';
		$relSrc = str_replace($projectRoot . '/', '', $srcDir);
		$storeDir = $srcDir . '/discovery';

		try {
			FileSystem::write(
				$srcDir . '/ScratchFactoryRenderer.php',
				"<?php declare(strict_types = 1);\n\n"
				. "/**\n"
				. " * @property-read Nette\\Bridges\\ApplicationLatte\\DefaultTemplate \$template\n"
				. " */\n"
				. "final class ScratchFactoryRenderer extends Nette\\Application\\UI\\Control\n"
				. "{\n\n"
				. ($standaloneFactoryRenderer ? self::STANDALONE_FACTORY_MEMBERS : '')
				. "\tpublic function render(): void\n"
				. "\t{\n"
				. "\t\t\$this->template->setFile(__DIR__ . '/" . ($useTemplate === null ? 'tpl' : 'use') . ".latte');\n"
				. "\t\t\$this->template->render();\n"
				. "\t}\n\n"
				. "}\n",
			);

			$templates = $useTemplate === null
				? [
					'tpl.latte' => "{if \$user === null}u{/if}\n"
						. "{if \$baseUrl === null}b{/if}\n"
						. "{if \$basePath === null}p{/if}\n"
						. "{if \$flashes === []}f{/if}\n"
						. "{if \$control === null}c{/if}\n"
						. "{if \$presenter === null}r{/if}\n"
						. "{\$undefinedVar}\n"
						. "{block probe}{if \$user === null}bu{/if}{/block}\n",
				]
				: ['use.latte' => $useTemplate];

			foreach ($templates as $basename => $source) {
				FileSystem::write($srcDir . '/' . $basename, $source);
			}

			// Every template's store file exists BEFORE run 1 (mirrors the pre-analysis index build):
			// the template->store-class dependency edge must be baked into the cold parse before any
			// record change can propagate through it.
			DiscoveryStore::bootstrap(
				$storeDir,
				array_map(static fn (string $basename): string => "$relSrc/$basename", array_keys($templates)),
			);

			$test($projectRoot, $srcDir, $relSrc, $storeDir);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	/**
	 * @return array{output: string, diagnostics: string}
	 */
	private function spawn(
		string $projectRoot,
		string $srcDir,
		string $tmpDir,
		string $storeDir,
		?string $containerLoaderFile
	): array
	{
		$parameters = [
			'orisaiNette.latte.discovery.enabled' => true,
			'orisaiNette.latte.discovery.storePath' => $storeDir,
			'orisaiNette.latte.firstPartyPaths' => [$srcDir],
		];
		if ($containerLoaderFile !== null) {
			$parameters['orisaiNette.latte.templateFactoryContainerLoader'] = $containerLoaderFile;
		}

		$isolated = LattePhpstanConfig::create(self::REAL_CONFIG_PATH, [$srcDir], $tmpDir, $parameters);

		$process = new Process(
			[
				PHP_BINARY,
				$projectRoot . '/vendor/bin/phpstan',
				'analyse',
				'--no-progress',
				'--level=8',
				'--error-format=raw',
				'-vv',
				'-c',
				$isolated->getConfigPath(),
			],
			$projectRoot,
		);
		$process->run();

		$rawOutput = preg_replace('/ \[identifier=[^\]]+\]$/m', '', $process->getOutput());

		return [
			'output' => $this->normalize($rawOutput ?? $process->getOutput(), $projectRoot),
			'diagnostics' => $process->getErrorOutput(),
		];
	}

	// The orphan verdict is a moving target BY DESIGN here (the store starts empty and the writer
	// links the template during the very runs this test measures), so it is dropped rather than
	// asserted - DiscoveryStoreInvalidationTest's own precedent.
	private function normalize(string $raw, string $projectRoot): string
	{
		$lines = [];
		foreach (explode("\n", str_replace($projectRoot . '/', '', $raw)) as $line) {
			$line = rtrim($line);
			if ($line === '' || strpos($line, self::ORPHAN_MESSAGE) !== false) {
				continue;
			}

			$lines[] = $line;
		}

		return implode("\n", $lines) . "\n";
	}

}
