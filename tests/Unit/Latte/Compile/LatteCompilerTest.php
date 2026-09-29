<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Compile;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use OriPhpstan\Nette\Latte\Cache\LatteAnalysisCache;
use OriPhpstan\Nette\Latte\Compile\CompileResult;
use OriPhpstan\Nette\Latte\Compile\LatteCompiler;
use OriPhpstan\Nette\Latte\Compile\TemplateClassName;
use OriPhpstan\Nette\Latte\Customs\CustomsHarvester;
use OriPhpstan\Nette\Latte\Customs\EngineSource;
use OriPhpstan\Nette\Latte\Customs\HarvestedCustoms;
use ReflectionMethod;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InstalledVersionsGuard;
use function getmypid;
use function glob;
use function ob_get_clean;
use function ob_start;
use function restore_error_handler;
use function set_error_handler;
use function sha1;
use function sys_get_temp_dir;
use function uniqid;

final class LatteCompilerTest extends BaseTestCase
{

	public function testCompilesCoreTemplate(): void
	{
		$compiler = new LatteCompiler();
		$result = $compiler->compile("{if \$show}<b>{\$name}</b>{/if}\n", 'LatteTpl_test');

		self::assertNotNull($result->getPhpSource());
		self::assertSame([], $result->getDiagnostics());
		self::assertStringContainsString(
			'final class LatteTpl_test extends Latte\Runtime\Template',
			$result->getPhpSource(),
		);
		self::assertStringContainsString('if ($show)', $result->getPhpSource());
	}

	public function testUnclosedTagProducesFailureDiagnostic(): void
	{
		$compiler = new LatteCompiler();
		$result = $compiler->compile("{if \$show}\nhello\n", 'LatteTpl_test');

		self::assertNull($result->getPhpSource());
		self::assertSame('LatteTpl_test', $result->getClassName());
		self::assertCount(1, $result->getDiagnostics());

		$diagnostic = $result->getDiagnostics()[0];
		self::assertSame('orisaiNette.latte.parseError', $diagnostic->getIdentifier());
		self::assertStringContainsString('Missing {/if}', $diagnostic->getMessage());
		self::assertSame(1, $diagnostic->getLatteLine());
	}

	public function testCompilesUiAndFormsMacros(): void
	{
		$compiler = new LatteCompiler();
		$source = "{form login}{input user}{/form}\n{control menu}\n<a n:href=\"detail id => 1\">x</a>\n";
		$result = $compiler->compile($source, 'LatteTpl_test');

		self::assertNotNull($result->getPhpSource());
		self::assertSame([], $result->getDiagnostics());
	}

	public function testDeterministicAcrossInstances(): void
	{
		$source = "{foreach \$items as \$item}{\$item|upper}{/foreach}\n";
		$a = (new LatteCompiler())->compile($source, 'LatteTpl_test');
		$b = (new LatteCompiler())->compile($source, 'LatteTpl_test');

		self::assertSame($a->getPhpSource(), $b->getPhpSource());
	}

	public function testCacheMacroIsDeterministic(): void
	{
		$source = "{cache \$id, expire => '20 minutes'}<b>{\$x}</b>{/cache}\n";
		$a = (new LatteCompiler())->compile($source, 'LatteTpl_test');
		$b = (new LatteCompiler())->compile($source, 'LatteTpl_test');

		self::assertNotNull($a->getPhpSource());
		self::assertSame($a->getPhpSource(), $b->getPhpSource());
	}

	public function testHarvestedGettextMacroSetCompilesNativelyWithoutUnknownMacroDiagnostics(): void
	{
		$compiler = new LatteCompiler(null, $this->gettextHarvester());
		$source = "{_'Hello'}\n{g_'Hi'}\n{ng_ 'one item', 'many items', \$count}\n"
			. "{dg_ 'domain', 'Domain text'}\n{dng_ 'domain', 'one item', 'many items', \$count}\n";

		$result = $compiler->compile($source, 'LatteTpl_test_gettext_native');

		self::assertNotNull($result->getPhpSource());
		self::assertSame([], $result->getDiagnostics());
		self::assertStringContainsString("gettext('Hello')", $result->getPhpSource());
		self::assertStringContainsString("gettext('Hi')", $result->getPhpSource());
		self::assertStringContainsString('ngettext(', $result->getPhpSource());
		self::assertStringContainsString("dgettext('domain', 'Domain text')", $result->getPhpSource());
		self::assertStringContainsString('dngettext(', $result->getPhpSource());
	}

	public function testHarvestedGettextFamilyCoexistsWithPassthroughForAGenuinelyUnknownMacro(): void
	{
		$compiler = new LatteCompiler(null, $this->gettextHarvester());
		$result = $compiler->compile("{_'Hello'}\n{foo}inner {\$x}{/foo}\n", 'LatteTpl_test_gettext_and_unknown');

		self::assertNotNull($result->getPhpSource());
		$identifiers = [];
		foreach ($result->getDiagnostics() as $diagnostic) {
			$identifiers[] = $diagnostic->getIdentifier();
		}

		self::assertSame(
			['orisaiNette.latte.unknownMacro'],
			$identifiers,
			'the unrelated {foo}/{/foo} pair must still fall through to passthrough - only it, not the harvested gettext call',
		);
		self::assertStringContainsString("gettext('Hello')", $result->getPhpSource());
		self::assertStringContainsString('$x', $result->getPhpSource());
	}

	public function testHarvestedBuiltInMacroSetsNeverShadowTheDeterministicCacheMacro(): void
	{
		$harvester = $this->fullAppShapeHarvester();
		$source = "{cache \$id, expire => '20 minutes'}<b>{\$x}</b>{/cache}\n";

		$a = (new LatteCompiler(null, $harvester))->compile($source, 'LatteTpl_test_cache_dedup');
		$b = (new LatteCompiler(null, $harvester))->compile($source, 'LatteTpl_test_cache_dedup');

		self::assertNotNull($a->getPhpSource());
		self::assertStringContainsString('latte-analysis-cache-', $a->getPhpSource());
		self::assertSame(
			$a->getPhpSource(),
			$b->getPhpSource(),
			'a harvested vendor CacheMacro instance (embeds Nette\Utils\Random::generate()) must never '
			. 'shadow the DeterministicCacheMacro replacement installed under the same name',
		);
	}

	public function testHarvestedBuiltInMacroSetsDoNotBreakCoreMacroCompilation(): void
	{
		$harvester = $this->fullAppShapeHarvester();
		$result = (new LatteCompiler(null, $harvester))->compile(
			"{if \$show}<b>{\$name}</b>{/if}\n",
			'LatteTpl_test_core_dedup',
		);

		self::assertNotNull($result->getPhpSource());
		self::assertSame([], $result->getDiagnostics());
		self::assertStringContainsString('if ($show)', $result->getPhpSource());
	}

	public function testHarvestedMacroSetInstallTriggerErrorIsContainedAndCompileProceeds(): void
	{
		// If installHarvestedMacroSets()'s VendorErrorContainment wrap were ever removed, PHPUnit's
		// own error-to-exception handler would convert the fixture's trigger_error() into a thrown
		// Warning before compile() returns - reaching the assertions below is already half the
		// regression proof. ob_start()/ob_get_clean() is the other half: proves nothing reached
		// stdout either, independent of which handler would have caught it.
		$engineLoaderFile = __DIR__ . '/../Customs/Fixtures/engine-loader-install-trigger-error.php';
		$harvester = new CustomsHarvester(new EngineSource(null, $engineLoaderFile));

		ob_start();
		$result = (new LatteCompiler(null, $harvester))->compile(
			"{fixtureInstallTriggerErrorMacro}inner{/fixtureInstallTriggerErrorMacro}\n",
			'LatteTpl_test_install_trigger_error',
		);
		$output = ob_get_clean();

		self::assertSame('', $output, 'install()-time trigger_error() output must never leak to stdout');
		self::assertNotNull($result->getPhpSource());
		self::assertSame([], $result->getDiagnostics());
		self::assertStringContainsString('fixtureInstallTriggerErrorMacro', $result->getPhpSource());
	}

	public function testHarvestedMacroSetsWithNoInstallOrThrowingInstallAreSkippedWithoutCrash(): void
	{
		// Two failure branches at once: FixtureNoInstallMacro has no static install() at all
		// (is_callable() guard), FixtureThrowingInstallMacroSet's install() always throws (the
		// try/catch (Throwable) guard) - harvest captures a real instance of both (registered via a
		// bare addMacro() call, never through either fixture's own broken/absent factory), so
		// LatteCompiler::installHarvestedMacroSets() is the first place either guard actually runs.
		// Both must degrade silently: compile of an unrelated template proceeds clean, no crash.
		$engineLoaderFile = __DIR__ . '/../Customs/Fixtures/engine-loader-failure-branches.php';
		$harvester = new CustomsHarvester(new EngineSource(null, $engineLoaderFile));

		$result = (new LatteCompiler(null, $harvester))->compile(
			"{if \$show}<b>{\$name}</b>{/if}\n",
			'LatteTpl_test_failure_branches',
		);

		self::assertNotNull($result->getPhpSource());
		self::assertSame([], $result->getDiagnostics());
		self::assertStringContainsString('if ($show)', $result->getPhpSource());
	}

	private function gettextHarvester(): CustomsHarvester
	{
		$engineLoaderFile = __DIR__ . '/../Customs/Fixtures/engine-loader-gettext.php';

		return new CustomsHarvester(new EngineSource(null, $engineLoaderFile));
	}

	private function fullAppShapeHarvester(): CustomsHarvester
	{
		InstalledVersionsGuard::requireNetteLine('nette/application', '<3.3');
		InstalledVersionsGuard::requireNetteLine('nette/forms', '<3.3');
		$engineLoaderFile = __DIR__ . '/../Customs/Fixtures/engine-loader-full-app-shape.php';

		return new CustomsHarvester(new EngineSource(null, $engineLoaderFile));
	}

	public function testInvalidUtf8ProducesParserFailureDiagnostic(): void
	{
		$compiler = new LatteCompiler();
		$result = $compiler->compile("{if \$a}\xC3\x28{/if}", 'LatteTpl_test');

		self::assertNull($result->getPhpSource());
		self::assertSame('LatteTpl_test', $result->getClassName());
		self::assertCount(1, $result->getDiagnostics());

		$diagnostic = $result->getDiagnostics()[0];
		self::assertSame('orisaiNette.latte.parseError', $diagnostic->getIdentifier());
		self::assertStringContainsString('not valid UTF-8', $diagnostic->getMessage());
	}

	public function testClassNameIsPathDerivedAndStable(): void
	{
		self::assertSame(
			TemplateClassName::forPath('app/templates/News/detail.latte'),
			TemplateClassName::forPath('app/templates/News/detail.latte'),
		);
		self::assertNotSame(
			TemplateClassName::forPath('app/templates/News/detail.latte'),
			TemplateClassName::forPath('app/templates/News/edit.latte'),
		);
		self::assertMatchesRegularExpression(
			'~^LatteTpl_app_templates_News_detail_latte_[0-9a-f]{8}$~',
			TemplateClassName::forPath('app/templates/News/detail.latte'),
		);
	}

	public function testUnknownMacroToleratedWithDiagnostic(): void
	{
		$compiler = new LatteCompiler();
		$result = $compiler->compile("{g_ 'text'}\n{foo}inner {\$x}{/foo}\n", 'LatteTpl_test');

		self::assertNotNull($result->getPhpSource());
		$identifiers = [];
		foreach ($result->getDiagnostics() as $diagnostic) {
			$identifiers[] = $diagnostic->getIdentifier();
		}

		self::assertSame(['orisaiNette.latte.unknownMacro', 'orisaiNette.latte.unknownMacro'], $identifiers);
		self::assertStringContainsString('$x', $result->getPhpSource());
	}

	public function testUnknownNAttributeTolerated(): void
	{
		$compiler = new LatteCompiler();
		$result = $compiler->compile("<div n:custom=\"\$a\">{\$b}</div>\n", 'LatteTpl_test');

		self::assertNotNull($result->getPhpSource());
		self::assertCount(1, $result->getDiagnostics());
		self::assertSame('orisaiNette.latte.unknownMacro', $result->getDiagnostics()[0]->getIdentifier());
	}

	public function testUnknownPrefixedNAttributeToleratedInSingleRetry(): void
	{
		$compiler = new LatteCompiler();
		$result = $compiler->compile("<div n:inner-custom=\"\$a\">{\$b}</div>\n", 'LatteTpl_test');

		self::assertNotNull($result->getPhpSource());
		self::assertCount(1, $result->getDiagnostics());
		self::assertSame('orisaiNette.latte.unknownMacro', $result->getDiagnostics()[0]->getIdentifier());
		self::assertStringContainsString('$b', $result->getPhpSource());
	}

	public function testBrokenTemplateFailsWithParseError(): void
	{
		$compiler = new LatteCompiler();
		$result = $compiler->compile("{if \$a}unclosed\n", 'LatteTpl_test');

		self::assertNull($result->getPhpSource());
		self::assertSame('orisaiNette.latte.parseError', $result->getDiagnostics()[0]->getIdentifier());
	}

	public function testUnknownMacroDiagnosticHasRealLine(): void
	{
		$compiler = new LatteCompiler();
		$result = $compiler->compile("foo\n{bar}\n", 'LatteTpl_test');

		self::assertNotNull($result->getPhpSource());
		self::assertCount(1, $result->getDiagnostics());
		self::assertSame(2, $result->getDiagnostics()[0]->getLatteLine());
	}

	public function testParseErrorDiagnosticHasRealLine(): void
	{
		$compiler = new LatteCompiler();
		$result = $compiler->compile("line1\n{if \$a}\nline3\n", 'LatteTpl_test');

		self::assertNull($result->getPhpSource());
		$diagnostic = $result->getDiagnostics()[0];
		self::assertSame('orisaiNette.latte.parseError', $diagnostic->getIdentifier());
		self::assertSame(2, $diagnostic->getLatteLine());
	}

	public function testMacroNameValidationFailureDiagnosticHasRealLine(): void
	{
		$compiler = new LatteCompiler();
		$result = $compiler->compile("<div n:1custom=\"\$a\">{\$b}</div>\n", 'LatteTpl_test');

		self::assertNull($result->getPhpSource());
		self::assertCount(1, $result->getDiagnostics());

		$diagnostic = $result->getDiagnostics()[0];
		self::assertSame('orisaiNette.latte.parseError', $diagnostic->getIdentifier());
		self::assertSame(1, $diagnostic->getLatteLine());
	}

	public function testUnknownSyntaxTagProducesParseErrorDiagnostic(): void
	{
		$compiler = new LatteCompiler();
		$result = $compiler->compile("{syntax bogus}\n{\$x}\n", 'LatteTpl_test');

		self::assertNull($result->getPhpSource());
		self::assertCount(1, $result->getDiagnostics());

		$diagnostic = $result->getDiagnostics()[0];
		self::assertSame('orisaiNette.latte.parseError', $diagnostic->getIdentifier());
		self::assertNotSame('', $diagnostic->getMessage());
	}

	public function testFunctionCaseMismatchIsReportedWithoutLeakingAPhpWarning(): void
	{
		$compiler = new LatteCompiler();

		// PhpWriter::replaceFunctionsPass's case-mismatch trigger_error(E_USER_WARNING) is live
		// for this specific call (Compiler::setFunctions() registers the stock 7, and Clamp/clamp
		// is one of them) - if it ever escaped LatteCompiler's containment, PHPUnit's default
		// error-to-exception handler would throw before compile() returns, so reaching the
		// assertions below is already half the regression proof.
		$result = $compiler->compile("{Clamp(\$v)}\n", 'LatteTpl_test_case_mismatch');

		self::assertNotNull($result->getPhpSource());
		$identifiers = [];
		foreach ($result->getDiagnostics() as $diagnostic) {
			$identifiers[] = $diagnostic->getIdentifier();
		}

		self::assertSame(['orisaiNette.latte.functionCaseMismatch'], $identifiers);
	}

	public function testFilterCaseMismatchIsReported(): void
	{
		$compiler = new LatteCompiler();
		$result = $compiler->compile("{\$v|Upper}\n", 'LatteTpl_test_filter_case_mismatch');

		self::assertNotNull($result->getPhpSource());
		self::assertCount(1, $result->getDiagnostics());
		self::assertSame('orisaiNette.latte.filterCaseMismatch', $result->getDiagnostics()[0]->getIdentifier());
	}

	public function testExactCaseFunctionCallHasNoCaseMismatchDiagnostic(): void
	{
		$compiler = new LatteCompiler();
		$result = $compiler->compile("{clamp(\$v)}\n", 'LatteTpl_test_no_case_mismatch');

		self::assertNotNull($result->getPhpSource());
		self::assertSame([], $result->getDiagnostics());
	}

	public function testDeprecatedConstructProducesLatteDeprecatedDiagnosticAtRealLine(): void
	{
		$compiler = new LatteCompiler();

		// <br> is a void HTML element (Helpers::$emptyElements) - n:ifcontent on it is exactly the
		// vendor E_USER_DEPRECATED condition (CoreMacros::macroIfContent), empirically confirmed to
		// leak raw "Deprecated: ..." text to stdout before this containment existed.
		$result = $compiler->compile("line1\n<br n:ifcontent>\n", 'LatteTpl_test_deprecated');

		self::assertNotNull($result->getPhpSource());
		self::assertCount(1, $result->getDiagnostics());

		$diagnostic = $result->getDiagnostics()[0];
		self::assertSame('orisaiNette.latte.deprecated', $diagnostic->getIdentifier());
		self::assertStringContainsString('n:ifcontent', $diagnostic->getMessage());
		self::assertSame(2, $diagnostic->getLatteLine());
	}

	public function testNonDeprecatedTemplateHasNoDeprecatedDiagnostic(): void
	{
		$compiler = new LatteCompiler();
		$result = $compiler->compile("<br>\n", 'LatteTpl_test_no_deprecated');

		self::assertNotNull($result->getPhpSource());
		self::assertSame([], $result->getDiagnostics());
	}

	public function testRecoverLineFallsBackToOneForUnavailableCompilerLine(): void
	{
		// orisaiNette.latte.deprecated's line attribution is $this->recoverLine($compiler->getLine()) -
		// verbatim the same fallback every other Diagnostic in this class already relies on. No
		// reachable vendor E_USER_DEPRECATED site leaves Compiler::getLine() genuinely null/<=0 at
		// trigger time (position tracking is always mid-token-loop when any of them fire), so this
		// pins the shared fallback directly rather than fighting vendor internals for a repro.
		$method = new ReflectionMethod(LatteCompiler::class, 'recoverLine');
		$method->setAccessible(true);
		$compiler = new LatteCompiler();

		self::assertSame(1, $method->invoke($compiler, null));
		self::assertSame(1, $method->invoke($compiler, 0));
		self::assertSame(1, $method->invoke($compiler, -3));
	}

	public function testErrorHandlerRestoredAfterSuccessfulCompile(): void
	{
		$sentinel = static fn (): bool => true;
		set_error_handler($sentinel);

		try {
			(new LatteCompiler())->compile("{if \$show}<b>{\$name}</b>{/if}\n", 'LatteTpl_test');

			self::assertSame($sentinel, self::currentErrorHandler());
		} finally {
			restore_error_handler();
		}
	}

	public function testErrorHandlerRestoredAfterCompileExceptionPath(): void
	{
		$sentinel = static fn (): bool => true;
		set_error_handler($sentinel);

		try {
			$result = (new LatteCompiler())->compile("{if \$show}\nhello\n", 'LatteTpl_test');

			self::assertNull($result->getPhpSource());
			self::assertSame($sentinel, self::currentErrorHandler());
		} finally {
			restore_error_handler();
		}
	}

	public function testErrorHandlerRestoredAfterDeprecatedConstructCompile(): void
	{
		$sentinel = static fn (): bool => true;
		set_error_handler($sentinel);

		try {
			(new LatteCompiler())->compile("<br n:ifcontent>\n", 'LatteTpl_test_deprecated_handler');

			self::assertSame($sentinel, self::currentErrorHandler());
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * @return (callable(mixed...): mixed)|null
	 */
	private static function currentErrorHandler(): ?callable
	{
		$probe = static fn (): bool => true;
		$current = set_error_handler($probe);
		restore_error_handler();

		return $current;
	}

	public function testSecondCompilerInstanceReusesCachedCompileWithoutRecompiling(): void
	{
		$dir = $this->isolatedCacheDir();
		$source = "{if \$show}<b>{\$name}</b>{/if}\n";
		$className = 'LatteTpl_test';

		// Pre-seeds the exact key LatteCompiler::compile() must read - a real compile of $source
		// could never itself produce this marker, so returning it proves the second instance took
		// the cache-hit path instead of running the vendor compiler. No harvester is passed below,
		// so the key's harvest salt is the harvester-absent HarvestedCustoms::empty() salt; no
		// discovery store either, so the discovery salt is the constant 'disabled'.
		$poisoned = CompileResult::success('<?php /* POISONED CACHE HIT MARKER */', $className, []);
		(new LatteAnalysisCache($dir, 'testv1'))->writeContentAddressed(
			sha1($source) . '|' . $className . '|' . HarvestedCustoms::empty()->getSaltHash() . '|disabled',
			'latte-compile',
			['result' => $poisoned],
		);

		$compiler = new LatteCompiler(new LatteAnalysisCache($dir, 'testv1'));
		$result = $compiler->compile($source, $className);

		self::assertSame('<?php /* POISONED CACHE HIT MARKER */', $result->getPhpSource());

		(new LatteAnalysisCache($dir, 'testv1'))->clear();
	}

	public function testCachedCompileIsByteIdenticalToFreshCompileIncludingDiagnostics(): void
	{
		$dir = $this->isolatedCacheDir();
		$source = "{g_ 'text'}\n{foo}inner {\$x}{/foo}\n";
		$className = 'LatteTpl_test';

		$cold = (new LatteCompiler(new LatteAnalysisCache($dir, 'testv1')))->compile($source, $className);
		$warm = (new LatteCompiler(new LatteAnalysisCache($dir, 'testv1')))->compile($source, $className);

		self::assertNotNull($cold->getPhpSource());
		self::assertSame($cold->getPhpSource(), $warm->getPhpSource());
		self::assertCount(2, $cold->getDiagnostics());
		self::assertEquals($cold->getDiagnostics(), $warm->getDiagnostics());

		(new LatteAnalysisCache($dir, 'testv1'))->clear();
	}

	public function testHarvestChangeInvalidatesTheCompileCacheEntryForTheSameSourceAndClassName(): void
	{
		$dir = $this->isolatedCacheDir();
		$source = "{g_'Hi'}\n";
		$className = 'LatteTpl_test_harvest_cache_invalidation';

		$harvestOn = (new LatteCompiler(new LatteAnalysisCache($dir, 'testv1'), $this->gettextHarvester()))
			->compile($source, $className);

		self::assertNotNull($harvestOn->getPhpSource());
		self::assertSame([], $harvestOn->getDiagnostics());
		self::assertStringContainsString("gettext('Hi')", $harvestOn->getPhpSource());

		// Same cache dir, same source, same className - only the harvester differs (none here). The
		// compile-cache key must fold the harvest salt, or this read incorrectly returns harvest-ON's
		// cached native-gettext CompileResult instead of compiling fresh.
		$harvestOff = (new LatteCompiler(new LatteAnalysisCache($dir, 'testv1')))->compile($source, $className);

		self::assertNotNull($harvestOff->getPhpSource());
		self::assertNotSame(
			$harvestOn->getPhpSource(),
			$harvestOff->getPhpSource(),
			'harvest-OFF sharing the same cache dir must not receive harvest-ON\'s compiled result',
		);

		$identifiers = [];
		foreach ($harvestOff->getDiagnostics() as $diagnostic) {
			$identifiers[] = $diagnostic->getIdentifier();
		}

		self::assertSame(
			['orisaiNette.latte.unknownMacro'],
			$identifiers,
			'a fresh compile with no harvester must fall back to passthrough, not the stale native compile',
		);

		(new LatteAnalysisCache($dir, 'testv1'))->clear();
	}

	public function testFailedCompileIsNeverCached(): void
	{
		$dir = $this->isolatedCacheDir();
		$source = "{if \$show}\nhello\n";
		$className = 'LatteTpl_test';

		$result = (new LatteCompiler(new LatteAnalysisCache($dir, 'testv1')))->compile($source, $className);

		self::assertNull($result->getPhpSource());
		$matches = glob($dir . '/v*/*.ser');
		self::assertTrue($matches === false || $matches === [], 'a failed compile must never write a cache entry');
	}

	// The discovery-store salt folds into the compile key the same way the harvest salt does:
	// consumers of the store bake record-derived shapes into cached analysis artifacts, so a record
	// change must miss here rather than serve a pre-change result.
	public function testDiscoveryStoreRecordsParticipateInTheCompileCacheKey(): void
	{
		$dir = $this->isolatedCacheDir();
		$storeRoot = $this->isolatedCacheDir();
		$source = "<b>{\$name}</b>\n";
		$className = TemplateClassName::forPath('a.latte');

		$storeDirA = $storeRoot . '/store-a';
		DiscoveryStore::bootstrap($storeDirA, ['a.latte']);
		(new DiscoveryStore($storeDirA))->replaceWith(
			['a.latte' => [['class' => 'App\\Foo', 'view' => null, 'kind' => 'setFile', 'certainty' => 'unknown']]],
			['App\\Foo'],
			[],
		);
		$storeDirB = $storeRoot . '/store-b';
		DiscoveryStore::bootstrap($storeDirB, ['a.latte']);

		$poisoned = CompileResult::success('<?php /* POISONED CACHE HIT MARKER */', $className, []);
		(new LatteAnalysisCache($dir, 'testv1'))->writeContentAddressed(
			sha1($source) . '|' . $className . '|' . HarvestedCustoms::empty()->getSaltHash()
			. '|' . (new DiscoveryStore($storeDirA))->recordsSaltForTemplateClass($className),
			'latte-compile',
			['result' => $poisoned],
		);

		$hitA = (new LatteCompiler(new LatteAnalysisCache($dir, 'testv1'), null, new DiscoveryStore($storeDirA), true))
			->compile($source, $className);
		self::assertSame('<?php /* POISONED CACHE HIT MARKER */', $hitA->getPhpSource());

		$missB = (new LatteCompiler(new LatteAnalysisCache($dir, 'testv1'), null, new DiscoveryStore($storeDirB), true))
			->compile($source, $className);
		self::assertNotNull($missB->getPhpSource());
		self::assertNotSame(
			'<?php /* POISONED CACHE HIT MARKER */',
			$missB->getPhpSource(),
			'a different record salt must miss the poisoned entry - the salt participates in the key',
		);

		(new LatteAnalysisCache($dir, 'testv1'))->clear();
		FileSystem::delete($storeRoot);
	}

	// LatteAnalysisCache persists across runs (CI caches its tmpDir), so the salt must be
	// PER-TEMPLATE: a store-wide hash would make every cached CompileResult unreachable whenever any
	// single record anywhere moved.
	public function testAnUnrelatedTemplatesRecordChangeKeepsThisTemplatesCompileCacheEntry(): void
	{
		$dir = $this->isolatedCacheDir();
		$storeRoot = $this->isolatedCacheDir();
		$source = "<b>{\$name}</b>\n";
		$classA = TemplateClassName::forPath('a.latte');
		$classB = TemplateClassName::forPath('b.latte');

		$storeDir = $storeRoot . '/store';
		DiscoveryStore::bootstrap($storeDir, ['a.latte', 'b.latte']);
		$records = [
			'a.latte' => [['class' => 'App\\Foo', 'view' => null, 'kind' => 'setFile', 'certainty' => 'unknown']],
			'b.latte' => [['class' => 'App\\Bar', 'view' => null, 'kind' => 'setFile', 'certainty' => 'unknown']],
		];
		(new DiscoveryStore($storeDir))->replaceWith($records, ['App\\Bar', 'App\\Foo'], []);

		$cache = new LatteAnalysisCache($dir, 'testv1');
		$before = new DiscoveryStore($storeDir);
		foreach ([$classA, $classB] as $className) {
			$cache->writeContentAddressed(
				sha1($source) . '|' . $className . '|' . HarvestedCustoms::empty()->getSaltHash()
				. '|' . $before->recordsSaltForTemplateClass($className),
				'latte-compile',
				['result' => CompileResult::success('<?php /* POISONED ' . $className . ' */', $className, [])],
			);
		}

		// Only a.latte's records move.
		$records['a.latte'] = [
			['class' => 'App\\Foo', 'view' => null, 'kind' => 'setFile', 'certainty' => 'happens'],
		];
		(new DiscoveryStore($storeDir))->replaceWith($records, ['App\\Bar', 'App\\Foo'], []);

		$compiler = new LatteCompiler(
			new LatteAnalysisCache($dir, 'testv1'),
			null,
			new DiscoveryStore($storeDir),
			true,
		);

		self::assertNotSame(
			'<?php /* POISONED ' . $classA . ' */',
			$compiler->compile($source, $classA)->getPhpSource(),
			'the template whose own records changed must miss its pre-change entry',
		);
		self::assertSame(
			'<?php /* POISONED ' . $classB . ' */',
			$compiler->compile($source, $classB)->getPhpSource(),
			'an unrelated template must still hit - a global salt would evict the whole persistent cache',
		);

		(new LatteAnalysisCache($dir, 'testv1'))->clear();
		FileSystem::delete($storeRoot);
	}

	public function testDiscoveryDisabledUsesAConstantSaltAndNeverTouchesTheStore(): void
	{
		$dir = $this->isolatedCacheDir();
		$source = "<b>{\$name}</b>\n";
		$className = 'LatteTpl_test_discovery_salt_disabled';

		$poisoned = CompileResult::success('<?php /* POISONED CACHE HIT MARKER */', $className, []);
		(new LatteAnalysisCache($dir, 'testv1'))->writeContentAddressed(
			sha1($source) . '|' . $className . '|' . HarvestedCustoms::empty()->getSaltHash() . '|disabled',
			'latte-compile',
			['result' => $poisoned],
		);

		// The store instance points at a directory that never exists: a hit proves the disabled
		// flag short-circuits to the constant salt before any store read.
		$compiler = new LatteCompiler(
			new LatteAnalysisCache($dir, 'testv1'),
			null,
			new DiscoveryStore($this->isolatedCacheDir() . '/never-created'),
			false,
		);

		self::assertSame(
			'<?php /* POISONED CACHE HIT MARKER */',
			$compiler->compile($source, $className)->getPhpSource(),
		);

		(new LatteAnalysisCache($dir, 'testv1'))->clear();
	}

	private function isolatedCacheDir(): string
	{
		return sys_get_temp_dir() . '/latte-compiler-cache-test-' . getmypid() . '-' . uniqid('', true);
	}

}
