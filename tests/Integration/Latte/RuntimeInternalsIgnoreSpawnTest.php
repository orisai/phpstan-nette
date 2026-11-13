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

// config/latte.neon ignores the runtime internals generated template code calls: with reportUnmatchedIgnoredErrors on
// (PHPStan's default) a project on either line never sees an unmatched-ignore error for them.
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

	// With every ignore entry overridden away the same corpus reports none of the internals either: Latte 2's
	// prepare() read the private Template::$blocks only through UIRuntime::initialize(), which the analysed code drops,
	// and Latte 3 needs no entry - shipmonk counts its Blocks/Source/ContentType constants as overrides of the vendor
	// Template's, and 3.1's $parentArgs is declared there. The entries every line shares (dead LatteTpl_* methods,
	// LATTE_EDGE_FINGERPRINT) are left out of the pin.
	public function testTheInstalledLinesInternalsAreNotReportedWithoutTheIgnoreEntries(): void
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

		self::assertSame([], $findings);
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
