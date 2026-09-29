<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration;

use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures\FixtureTemplateTypeCustoms;
use function dirname;
use function uniqid;
use const PHP_BINARY;

// Real subprocess spawns (LatteDebugDumpIntegrationTest's own pattern), covering the three states
// dumpLatteCustoms() documents: a populated global harvest, a configured-but-empty one
// (EngineSource resolves but the resolved engine never produces anything - here forced via a
// throwing engine-loader, a distinct state from "nothing configured at all", which
// LatteDebugDumpRuleTest already pins at the unit level), and per-template ({templateType})
// entries - plus the bare unqualified {do dumpLatteCustoms()} form a real compiled template
// actually emits (compiled Latte classes carry no `namespace`, so the FQN
// form the other three spawns use here essentially never occurs in practice).
/**
 * @group latte2
 */
final class LatteDebugDumpCustomsIntegrationTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	private const ENGINE_LOADER = __DIR__ . '/../../../Unit/Latte/Customs/Fixtures/engine-loader.php';

	private const ENGINE_LOADER_THROWING = __DIR__ . '/../../../Unit/Latte/Customs/Fixtures/engine-loader-throwing.php';

	private const TEMPLATE_TYPE_CLASS = FixtureTemplateTypeCustoms::class;

	// The full expected text below is a LITERAL, hand-verified pin (not derived via the rule's own
	// sort/join/orig-case algorithm run a second time here) - obtained once from a real harvest of
	// this exact engine-loader fixture and pinned by hand, the same "shared bug in both copies
	// would otherwise go undetected" discipline LatteDebugDumpRuleTest's own describeUnion() test
	// note states. A real Latte\Engine unconditionally registers ~40 stock filters/functions/macros
	// in its constructor, so this pin will need a manual refresh on a latte/latte upgrade that
	// changes the stock set - an accepted, rare cost for a literal, independently-verifiable proof
	// of the orig-case-suffix format (escapeCss, firstUpper, padLeft, ... below are genuine
	// non-ambiguous case-differing vendor filters, not contrived).
	private const EXPECTED_POPULATED_MESSAGE = <<<'TEXT'
		global filters: batch, breaklines, bytes, capitalize, ceil, checkurl (checkUrl), clamp, datastream, date, escapecss (escapeCss), escapehtml (escapeHtml), escapehtmlcomment (escapeHtmlComment), escapeical (escapeICal), escapejs (escapeJs), escapeurl (escapeUrl), escapexml (escapeXml), explode, first, firstupper (firstUpper), fixturefilter (fixtureFilter), floor, implode, indent, join, last, length, lower, number, padleft (padLeft), padright (padRight), query, random, repeat, replace, replacere, reverse, round, slice, sort, spaceless, split, strip, striphtml, striptags, substr, trim, truncate, upper, webalize
		global functions: clamp, divisibleBy, even, first, fixtureFunction, last, odd, slice
		global macros: =, _, attr, block, breakIf, capture, case, class, contentType, continueIf, debugbreak, default, define, do, dump, else, elseif, elseifset, embed, extends, first, fixtureMacro, for, foreach, if, ifchanged, ifcontent, ifset, import, include, includeblock, iterateWhile, l, last, layout, parameters, php, r, rollback, sandbox, sep, skipIf, snippet, snippetArea, spaceless, switch, tag, templatePrint, templateType, trace, translate, try, var, varPrint, varType, while
		template: (none)
		template filters: (none)
		template functions: (none)
		TEXT;

	public function testPopulatedHarvestListsGlobalEntriesByKindWithOrigCaseNamesWhereTheyDiffer(): void
	{
		$message = $this->dumpOne($this->spawn(
			"{do \\OriPhpstan\\Nette\\Latte\\Testing\\dumpLatteCustoms()}\nHello.\n",
			['orisaiNette.latte.engineLoader' => self::ENGINE_LOADER],
		));

		self::assertSame(self::EXPECTED_POPULATED_MESSAGE, $message['message']);
		self::assertSame('orisaiNette.latte.debugDump', $message['identifier']);
		self::assertFalse($message['ignorable']);
	}

	// {do dumpLatteCustoms()} unqualified is the form a real compiled
	// template body actually contains (no `namespace` statement survives compilation), never the
	// FQN form the other spawns above use - the bare-form unit test in LatteDebugDumpRuleTest only
	// proves the rule's own string matching against a hand-built FuncCall node, not that the real
	// pipeline produces that node shape for this third dispatch arm.
	public function testBareUnqualifiedFormFiresThroughTheRealCompiledPipeline(): void
	{
		$message = $this->dumpOne($this->spawn("{do dumpLatteCustoms()}\nHello.\n", []));

		self::assertSame(
			'global: no harvest source configured (orisaiNette.dic.containerLoader / orisaiNette.latte.engineLoader)'
			. "\ntemplate: (none)\ntemplate filters: (none)\ntemplate functions: (none)",
			$message['message'],
		);
		self::assertSame('orisaiNette.latte.debugDump', $message['identifier']);
	}

	public function testEmptyHarvestReportsNoneForEveryGlobalKindWhenTheConfiguredSourceYieldsNothing(): void
	{
		$message = $this->dumpOne($this->spawn(
			"{do \\OriPhpstan\\Nette\\Latte\\Testing\\dumpLatteCustoms()}\nHello.\n",
			['orisaiNette.latte.engineLoader' => self::ENGINE_LOADER_THROWING],
		));

		self::assertSame(
			"global filters: (none)\nglobal functions: (none)\nglobal macros: (none)"
			. "\ntemplate: (none)\ntemplate filters: (none)\ntemplate functions: (none)",
			$message['message'],
		);
	}

	public function testPerTemplateEntriesAreListedWithTheDeclaringClassAndGlobalReportsNoSourceConfigured(): void
	{
		$message = $this->dumpOne($this->spawn(
			'{templateType ' . self::TEMPLATE_TYPE_CLASS . "}\n"
			. "{do \\OriPhpstan\\Nette\\Latte\\Testing\\dumpLatteCustoms()}\nHello.\n",
			[],
		));

		self::assertSame(
			'global: no harvest source configured (orisaiNette.dic.containerLoader / orisaiNette.latte.engineLoader)'
			. "\ntemplate: " . self::TEMPLATE_TYPE_CLASS
			. "\ntemplate filters: myTplFilter (" . self::TEMPLATE_TYPE_CLASS . ')'
			. "\ntemplate functions: myTplFunction (" . self::TEMPLATE_TYPE_CLASS . ')',
			$message['message'],
		);
	}

	/**
	 * @param list<array{message: string, line: int, ignorable: bool, identifier: string}> $messages
	 * @return array{message: string, line: int, ignorable: bool, identifier: string}
	 */
	private function dumpOne(array $messages): array
	{
		self::assertCount(1, $messages);

		return $messages[0];
	}

	/**
	 * @param array<string, bool|int|string|list<string>> $extraParameters
	 * @return list<array{message: string, line: int, ignorable: bool, identifier: string}>
	 */
	private function spawn(string $latte, array $extraParameters): array
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $projectRoot . '/var/tmp/latte-debug-dump-customs-integration-test-' . uniqid('', true);
		$srcDir = $scratch . '/src';
		FileSystem::createDir($srcDir);

		try {
			FileSystem::write($srcDir . '/target.latte', $latte);

			$isolated = LattePhpstanConfig::create(
				self::REAL_CONFIG_PATH,
				[$srcDir],
				$scratch . '/pstmp',
				$extraParameters,
			);

			try {
				$process = new Process(
					[
						PHP_BINARY,
						$projectRoot . '/' . VendorDirectory::name() . '/bin/phpstan',
						'analyse',
						'--no-progress',
						'--level=8',
						'--error-format=json',
						'-c',
						$isolated->getConfigPath(),
					],
					$projectRoot,
				);
				$process->setTimeout(120.0);
				$process->run();

				/** @var array{files: array<string, array{messages: list<array{message: string, line: int, ignorable: bool, identifier: string}>}>} $decoded */
				$decoded = Json::decode($process->getOutput(), Json::FORCE_ARRAY);

				$messages = [];
				foreach ($decoded['files'] as $fileMessages) {
					foreach ($fileMessages['messages'] as $message) {
						if ($message['identifier'] !== 'orisaiNette.latte.debugDump') {
							continue;
						}

						$messages[] = $message;
					}
				}

				return $messages;
			} finally {
				$isolated->cleanup();
			}
		} finally {
			FileSystem::delete($scratch);
		}
	}

}
