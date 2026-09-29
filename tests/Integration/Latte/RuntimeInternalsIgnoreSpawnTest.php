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

// config/latte.neon ignores the runtime internals every generated template class carries, per Latte
// line: with reportUnmatchedIgnoredErrors on (PHPStan's default) the installed line's entries match
// and the other line's entries stay silent, so a project never sees an unmatched-ignore error.
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

		switch (TestAdapter::factory()->family()->latteLine) {
			case ShapeFamily::LATTE_2:
				self::assertSame([
					'page.latte:4 property.private Access to private property $blocks of parent class Latte\Runtime\Template.',
					'xml.latte:3 property.private Access to private property $blocks of parent class Latte\Runtime\Template.',
				], $findings);

				break;
			case ShapeFamily::LATTE_30:
				self::assertSame([
					'page.latte:2 shipmonk.deadConstant Unused LatteTpl_src_page_latte_25b59905::Blocks',
					'page.latte:2 shipmonk.deadConstant Unused LatteTpl_src_page_latte_25b59905::Source',
					'xml.latte:3 shipmonk.deadConstant Unused LatteTpl_src_xml_latte_68cccc7e::ContentType',
					'xml.latte:3 shipmonk.deadConstant Unused LatteTpl_src_xml_latte_68cccc7e::Source',
				], $findings);

				break;
			default:
				self::assertSame([
					'child.latte:2 property.notFound Access to an undefined property LatteTpl_src_child_latte_77b4ac5f::$parentArgs.',
					'child.latte:2 shipmonk.deadConstant Unused LatteTpl_src_child_latte_77b4ac5f::Blocks',
					'page.latte:2 shipmonk.deadConstant Unused LatteTpl_src_page_latte_25b59905::Blocks',
					'xml.latte:3 shipmonk.deadConstant Unused LatteTpl_src_xml_latte_68cccc7e::ContentType',
				], $findings);
		}
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
				'orisaiNette' => ['latte' => ['enabled' => true]],
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
