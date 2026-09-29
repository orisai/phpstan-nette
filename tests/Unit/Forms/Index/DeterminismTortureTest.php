<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index;

use Nette\Utils\Json;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\IsolatedPhpstanConfig;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function array_merge;
use function array_reverse;
use function basename;
use function dirname;
use function glob;
use function preg_match;
use function sha1;
use function sort;
use function strpos;
use function trim;
use function usort;
use const PHP_BINARY;

/**
 * Determinism torture: the same fixture corpus, analysed with orisaiNette.forms.internals.indexShadowCompare on, must
 * report byte-identical (file, line, identifier, message) error sets regardless of the order the
 * files are handed to phpstan on the command line — glob order, reversed, and a sha1-of-basename
 * sort (a deterministic "shuffle", no randomness). Each order runs fully cold in its own
 * IsolatedPhpstanConfig tmpDir, so a divergence could only come from genuine fold-order
 * sensitivity, never from a shared warm cache.
 *
 * A second pair of runs targets the merge-order binding from A6: one handler key fed by TWO
 * registration sites (a trait-declared createComponentAlpha and a using-class createComponentBeta)
 * whose shapes conflict on the same slot name ('x': TextInput vs Checkbox). The fold is
 * CLI-order-independent by construction — RegistrationIndex enumerates a sorted file universe and
 * handlerSites() returns a contributor-id-sorted list (A5), so IndexShapeResolver's merge sequence is
 * fixed regardless of which file phpstan analyses first. The rendered index answer must therefore
 * stay byte-identical across all three orders; the CLI permutation guards that this constructed
 * order-independence is not silently broken. Post-flip there is no collector store to compare against
 * — readers consult the index directly — so the rule renders the index's answer alone.
 *
 * A third set targets the C8 classComponent flip: `$this[x]` resolution is on-demand-sole
 * (IndexShapeResolver::classComponentShape through ContainerModel's demand cache), so its answer must
 * not depend on whether the read or any collector pass over another file happens first. The
 * OffsetOrder corpus reads a same-class component ABOVE its createComponent in source (fiber-hostile)
 * and reaches a cross-file `$this[x]` chain through a `$this->prop`-returning createComponent; the
 * full error tuples (including the dumped leaf types) must be byte-identical across the same three
 * cold CLI orders.
 */
final class DeterminismTortureTest extends BaseTestCase
{

	private const MATRIX_ASSERT_CONFIG = __DIR__ . '/shadow-compare-php84.neon';

	private const MERGE_ORDER_CONFIG = __DIR__ . '/shadow-compare.neon';

	private const MERGE_ORDER_KEY = 'mp:Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\MergeOrder\MergeOrderConflict::sharedSucceeded#0';

	public function testMatrixAssertErrorSetIsOrderInvariant(): void
	{
		$files = glob(dirname(__DIR__, 3) . '/Doubles/Forms/MatrixAssert/*.php');
		self::assertNotFalse($files);
		self::assertNotSame([], $files);

		$glob = $this->errorTuples(self::MATRIX_ASSERT_CONFIG, $files);
		$reversed = $this->errorTuples(self::MATRIX_ASSERT_CONFIG, array_reverse($files));
		$sha1Sorted = $this->errorTuples(self::MATRIX_ASSERT_CONFIG, $this->sha1BasenameSort($files));

		self::assertNotSame([], $glob, 'sanity: the corpus must report at least one message');
		self::assertSame($glob, $reversed, 'glob vs reversed file order must report identical error sets');
		self::assertSame($glob, $sha1Sorted, 'glob vs sha1-shuffled file order must report identical error sets');
	}

	public function testOffsetOrderErrorSetIsOrderInvariant(): void
	{
		$files = glob(__DIR__ . '/Fixtures/OffsetOrder/*.php');
		self::assertNotFalse($files);
		self::assertNotSame([], $files);

		$glob = $this->errorTuples(self::MERGE_ORDER_CONFIG, $files);
		$reversed = $this->errorTuples(self::MERGE_ORDER_CONFIG, array_reverse($files));
		$sha1Sorted = $this->errorTuples(self::MERGE_ORDER_CONFIG, $this->sha1BasenameSort($files));

		self::assertNotSame([], $glob, 'sanity: the corpus must report at least one message');
		self::assertSame($glob, $reversed, 'the $this[x] resolutions must be identical, glob vs reversed order');
		self::assertSame(
			$glob,
			$sha1Sorted,
			'the $this[x] resolutions must be identical, glob vs sha1-shuffled order',
		);
	}

	public function testMergeOrderFixtureIndexAnswerIsOrderInvariant(): void
	{
		$files = glob(__DIR__ . '/Fixtures/MergeOrder/*.php');
		self::assertNotFalse($files);
		self::assertNotSame([], $files);

		$glob = $this->mergeOrderIndexRendering(self::MERGE_ORDER_CONFIG, $files);
		$reversed = $this->mergeOrderIndexRendering(self::MERGE_ORDER_CONFIG, array_reverse($files));
		$sha1Sorted = $this->mergeOrderIndexRendering(self::MERGE_ORDER_CONFIG, $this->sha1BasenameSort($files));

		self::assertNotNull($glob, 'the conflicting-slot fixture must produce an index rendering to observe');
		self::assertSame($glob, $reversed, 'the index-side rendering must be identical, glob vs reversed order');
		self::assertSame(
			$glob,
			$sha1Sorted,
			'the index-side rendering must be identical, glob vs sha1-shuffled order',
		);
	}

	/**
	 * @param list<string> $files
	 * @return list<string>
	 */
	private function sha1BasenameSort(array $files): array
	{
		$sorted = $files;
		usort($sorted, static fn (string $a, string $b): int => sha1(basename($a)) <=> sha1(basename($b)));

		return $sorted;
	}

	/**
	 * @param list<string> $files
	 * @return list<string>
	 */
	private function errorTuples(string $configFile, array $files): array
	{
		$decoded = $this->analyse($configFile, $files);

		$tuples = [];
		foreach ($decoded['files'] ?? [] as $file => $info) {
			foreach ($info['messages'] ?? [] as $message) {
				$tuples[] = basename($file) . ':' . $message['line']
					. ' [' . ($message['identifier'] ?? '') . '] ' . $message['message'];
			}
		}

		sort($tuples);

		return $tuples;
	}

	/**
	 * @param list<string> $files
	 */
	private function mergeOrderIndexRendering(string $configFile, array $files): ?string
	{
		$decoded = $this->analyse($configFile, $files);

		foreach ($decoded['files'] ?? [] as $info) {
			foreach ($info['messages'] ?? [] as $message) {
				if (($message['identifier'] ?? '') !== 'orisaiNette.forms.shadowDivergence') {
					continue;
				}

				if (strpos($message['message'], self::MERGE_ORDER_KEY) === false) {
					continue;
				}

				if (preg_match('~index = (.*)$~s', $message['message'], $m) === 1) {
					return trim($m[1]);
				}
			}
		}

		return null;
	}

	/**
	 * @param list<string> $files
	 * @return array{files?: array<string, array{messages?: list<array{identifier?: string, line: int, message: string}>}>}
	 */
	private function analyse(string $configFile, array $files): array
	{
		$root = dirname(__DIR__, 4);
		$isolated = IsolatedPhpstanConfig::create($configFile);

		try {
			$process = new Process(
				array_merge(
					[PHP_BINARY, $root . '/' . VendorDirectory::name() . '/bin/phpstan', 'analyse'],
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

			/** @var array{files?: array<string, array{messages?: list<array{identifier?: string, line: int, message: string}>}>} $decoded */
			$decoded = Json::decode($process->getOutput(), Json::FORCE_ARRAY);

			return $decoded;
		} finally {
			$isolated->cleanup();
		}
	}

}
