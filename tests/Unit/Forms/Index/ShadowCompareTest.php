<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index;

use Nette\Utils\Json;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\IsolatedPhpstanConfig;
use function array_keys;
use function array_merge;
use function dirname;
use function glob;
use function preg_match;
use function sort;
use const PHP_BINARY;

/**
 * The store→read seam is dead: readers consult the RegistrationIndex directly, so there is no collector
 * store to shadow-compare against. This test now pins the index itself. It drives the DumpType +
 * MatrixAssert corpus with the orisaiNette.forms.internals.indexShadowCompare flag on (ShadowDivergenceRule renders the index's
 * answer for every handler-param key the corpus produces) and asserts:
 *
 *   1. the SET of keys the index resolves cold is exactly the pinned set — a key that stops resolving
 *      (a coverage regression) or a new resolving key (unacknowledged coverage) both fail;
 *   2. the rendered shape of the C-class key HandlerParam::orderSucceeded#0 is byte-stable — its nested
 *      `delivery` container renders as the configured default container class (the scope-dependent label
 *      the index resolves the same way cold on every run), pinning that the shape does not drift.
 *
 * The DumpType set runs under the php74 harness (shadow-compare); MatrixAssert carries php8 syntax and
 * runs under shadow-compare-php84. Leaf-type coverage for the same fixtures lives in ComponentDumpTypeTest.
 *
 * Every interprocedural kind is now flipped, forFactoryMethod last (C9): the collector writes are gone and
 * ContainerModel::factoryMethodShape / plainFileShape and IndexShapeResolver::classComponentShape compute
 * on demand as the sole sources, so there is no store left to shadow-compare for any kind. The former
 * store-vs-twin factory comparison retired with the flip. Reader-side behaviour for the factory families
 * the C6/C7 triage catalogued (OPENTAIL,
 * FACTORY-NEW, the addComponent-fold, CONTAINER-LABEL vantage) is pinned by ComponentDumpTypeTest — the
 * C9 twin extension gave ContainerModel::shapeVendorMethod's var arm the same constructorShape
 * compensation its factory merge already applies, so a `$form = new X()` factory whose fields come
 * from X's own constructor carries them and a configure-only constructor's open tail is preserved.
 */
final class ShadowCompareTest extends BaseTestCase
{

	/**
	 * Every handler-param key the DumpType + MatrixAssert corpus resolves through the index cold.
	 */
	private const RESOLVED_KEYS = [
		'mp:Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\ClosureShadowedHandlerParamStaysDeclared::onOk#0',
		'mp:Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\DisabledInConstructor::process#0',
		'mp:Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\HandlerParam::orderSucceeded#0',
		'mp:Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\HelperFormParamResolves::renderForm#0',
		'mp:Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\MClosureLocalCtorDoesNotPollute::process#0',
		'mp:Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\MClosureLocalFactoryDoesNotPollute::process#0',
		'mp:Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\MConstructorBuiltForm::process#0',
		'mp:Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\MConstructorConfigureOnlyClosed::process#0',
		'mp:Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\MConstructorConfigureOnlyRebindClosed::process#0',
		'mp:Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\MConstructorHelperAddStaysOpen::process#0',
		'mp:Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\MConstructorHelperOffsetStaysOpen::process#0',
		'mp:Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\MConstructorProtectedHelperStaysOpen::process#0',
		'mp:Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\MConstructorVarConstConcatNames::process#0',
		'mp:Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\MEventArrayCallableResolves::handleSuccess#0',
		'mp:Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\MEventLocalFormReceiverResolves::handleEdit#0',
		'mp:Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\MEventStoredCallbackSpellings::processesOnly#0',
		'mp:Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\MEventStoredCallbackSpellings::registersOnForm#0',
		'mp:Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\MFormOnSuccessHandlerStyles::handleSuccess#0',
		'mp:Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\MFormOnSuccessHandlerStylesShuffled::handleSuccess#0',
		'mp:Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\MParamPassThroughHelperResolves::innerHelper#0',
		'mp:Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\MParamPassThroughHelperResolves::outerHelper#0',
		'mp:Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\MParentThenPropertyRebindStaysOpen::process#0',
		'mp:Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\MTraitConstructorClassOverrides::process#0',
		'mp:Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\MTraitConstructorForm::process#0',
		'mp:Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\MTraitConstructorInsteadof::process#0',
		'mp:Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\MTraitFactoryAsAlias::process#0',
		'mp:Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\MTraitFactoryViaChain::process#0',
		'mp:Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\ScopeFreeConcreteLabelsResolve::onOk#0',
		'mp:Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\ScopeFreeConcreteLabelsResolve::onOkDerived#0',
		'mp:Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\UndecidableStaysIComponent::fill#0',
		'mp:Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert\RemoteNewFactoryControl::onEditorSuccess#0',
	];

	private const HANDLER_PARAM_KEY = 'mp:Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\HandlerParam::orderSucceeded#0';

	// Since the C8 classComponent flip the reader-time on-demand walk always precedes the end-of-run
	// index rendering (no store hit preempts it), so the shared per-node summaries carry the reader
	// vantage: the scope-resolved base container label (Nette\Forms\Container, the collector's old
	// convention). Coarser label only — fields identical. The shape is CLOSED: the fixture's
	// `$form = new Form()` contributes a provably empty constructor shape, so nothing opens it.
	private const HANDLER_PARAM_RENDER = "Nette\\Application\\UI\\Form{\n"
		. "  delivery: Nette\\Forms\\Container{\n"
		. "    note: Nette\\Forms\\Controls\\TextInput<bool|float|int|string|Stringable|null, string>,\n"
		. "  },\n"
		. '}';

	public function testResolvedKeySetAndCClassRenderingArePinned(): void
	{
		$fixturesDir = dirname(__DIR__);

		$dumpType = glob($fixturesDir . '/Component/Fixtures/DumpType/*.php');
		$matrix = glob(dirname(__DIR__, 3) . '/Doubles/Forms/MatrixAssert/*.php');
		self::assertNotFalse($dumpType);
		self::assertNotFalse($matrix);
		self::assertNotSame([], $dumpType);
		self::assertNotSame([], $matrix);

		$dumpTypeMessages = $this->shadowMessages(__DIR__ . '/shadow-compare.neon', $dumpType);
		$matrixMessages = $this->shadowMessages(__DIR__ . '/shadow-compare-php84.neon', $matrix);

		$renders = array_merge(
			$this->indexRenders($dumpTypeMessages),
			$this->indexRenders($matrixMessages),
		);

		$found = array_keys($renders);
		sort($found);
		$expected = self::RESOLVED_KEYS;
		sort($expected);

		self::assertSame(
			$expected,
			$found,
			'the set of handler-param keys the index resolves cold has changed — reconcile the pinned set',
		);

		self::assertSame(
			self::HANDLER_PARAM_RENDER,
			$renders[self::HANDLER_PARAM_KEY] ?? '<absent>',
			'the C-class handler-param shape drifted (nested container label / field set)',
		);
	}

	/**
	 * @param list<string> $files
	 * @return list<array{identifier?: string, message: string}>
	 */
	private function shadowMessages(string $configFile, array $files): array
	{
		$root = dirname(__DIR__, 4);
		$isolated = IsolatedPhpstanConfig::create($configFile);

		try {
			$process = new Process(
				array_merge(
					[PHP_BINARY, $root . '/vendor/bin/phpstan', 'analyse'],
					$files,
					[
						'-c',
						$isolated->getConfigPath(),
						'--error-format=json',
						'--no-progress',
						'--memory-limit=2048M',
					],
				),
				$root,
			);
			$process->setTimeout(600.0);
			$process->run();

			/** @var array{files?: array<string, array{messages?: list<array{identifier?: string, message: string}>}>} $decoded */
			$decoded = Json::decode($process->getOutput(), Json::FORCE_ARRAY);

			$messages = [];
			foreach ($decoded['files'] ?? [] as $info) {
				foreach ($info['messages'] ?? [] as $message) {
					$messages[] = $message;
				}
			}

			return $messages;
		} finally {
			$isolated->cleanup();
		}
	}

	/**
	 * @param list<array{identifier?: string, message: string}> $messages
	 * @return array<string, string>  key => rendered index shape
	 */
	private function indexRenders(array $messages): array
	{
		$renders = [];
		foreach ($messages as $message) {
			if (($message['identifier'] ?? '') !== 'orisaiNette.forms.shadowDivergence') {
				continue;
			}

			if (preg_match('~for (mp:\S+#\d+):\nindex = (.*)$~s', $message['message'], $m) === 1) {
				$renders[$m[1]] = $m[2];
			}
		}

		return $renders;
	}

}
