<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte;

use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use Tests\OriPhpstan\Nette\Integration\Configuration\ConfigurationCorpus;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\ScratchProject;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;
use function basename;
use function in_array;
use function strpos;

// config/latte.neon ignores the Latte 2 runtime internals every generated template class carries: with
// reportUnmatchedIgnoredErrors on (PHPStan's default) a Latte 3 project never sees an unmatched-ignore
// error for them.
final class RuntimeInternalsIgnoreSpawnTest extends BaseTestCase
{

	private const RUNTIME_INTERNAL_IDENTIFIERS = [
		'ignore.unmatched',
		'shipmonk.deadConstant',
		'shipmonk.deadMethod',
		'property.private',
		'property.notFound',
		'method.internal',
		'staticMethod.internalClass',
	];

	private ScratchProject $project;

	protected function setUp(): void
	{
		parent::setUp();
		$this->project = ScratchProject::create('runtime-internals-ignore');
	}

	protected function tearDown(): void
	{
		$this->project->cleanup();
		parent::tearDown();
	}

	public function testNoRuntimeInternalAndNoUnmatchedIgnoreOnTheInstalledLine(): void
	{
		self::assertSame([], $this->runtimeInternalFindings([]));
	}

	// With every ignore entry overridden away the same corpus reports the line's internals, which
	// pins that the entries are needed and that the spawn sees what a project sees. The entries
	// every line shares (dead LatteTpl_* methods, LATTE_EDGE_FINGERPRINT) are left out of the pin.
	// Latte 3 needs none: shipmonk counts its Blocks/Source/ContentType constants as overrides of the
	// vendor Template's, and 3.1's $parentArgs is declared there.
	public function testTheInstalledLinesInternalsAreReportedWithoutTheIgnoreEntries(): void
	{
		$findings = [];
		foreach ($this->runtimeInternalFindings(['ignoreErrors!' => []]) as $finding) {
			if (
				strpos($finding, 'shipmonk.deadMethod') === false
				&& strpos($finding, 'LATTE_EDGE_FINGERPRINT') === false
			) {
				$findings[] = $finding;
			}
		}

		self::assertSame(
			TestAdapter::factory()->family()->latteLine === ShapeFamily::LATTE_2
				? [
					'page.latte:4 property.private Access to private property $blocks of parent class Latte\Runtime\Template.',
					'xml.latte:3 property.private Access to private property $blocks of parent class Latte\Runtime\Template.',
				]
				: [],
			$findings,
		);
	}

	/**
	 * @param array<string, mixed> $parameters
	 * @return list<string>
	 */
	private function runtimeInternalFindings(array $parameters): array
	{
		$this->project->write(
			'src/page.latte',
			"{varType string \$x}\n{block content}{\$x}{/block}\n{snippet s}{\$x}{/snippet}\n{include #content}\n",
		);
		$this->project->write('src/xml.latte', "{contentType application/xml}\n{varType string \$x}\n<a>{\$x}</a>\n");
		if (TestAdapter::factory()->family()->latteLine === ShapeFamily::LATTE_31) {
			$this->project->write(
				'src/child.latte',
				"{extends 'page.latte', x => 'a'}\n{block content}child{/block}\n",
			);
		}

		$result = $this->project->analyse(
			ConfigurationCorpus::PHPSTAN_NETTE_SWITCHES + $parameters + [
				'fileExtensions' => ['php', 'latte'],
				'reportUnmatchedIgnoredErrors' => true,
				'orisai' => ['nette' => ['latte' => ['enabled' => true]]],
			],
			['src'],
		);
		self::assertSame([], $result['errors'], $result['stderr']);

		$findings = [];
		foreach ($result['messages'] as $message) {
			if (in_array($message['identifier'], self::RUNTIME_INTERNAL_IDENTIFIERS, true)) {
				$findings[] = basename($message['file']) . ':' . $message['line'] . ' ' . $message['identifier'] . ' '
					. $message['message'];
			}
		}

		return $findings;
	}

}
