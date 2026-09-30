<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Corpus;

use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use OriPhpstan\Nette\Support\ProjectInstalledVersions;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\ScratchProject;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function array_keys;
use function array_merge;
use function array_unique;
use function count;
use function dirname;
use function fwrite;
use function getenv;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function ksort;
use function sort;
use function sprintf;
use function strlen;
use function strncmp;
use function substr;
use const STDERR;

// One PHPStan run over the upstream test templates harvested for the installed versions (make
// corpus-harvest). Every template must end up analysed or as a reported compile error, exactly as
// the committed manifest records; an internal error or unparsable generated code fails regardless.
// CORPUS_MANIFEST_WRITE=1 (make corpus-manifest) rewrites the manifest and prints the state diff.
/**
 * @group corpus
 */
final class CorpusManifestTest extends BaseTestCase
{

	private const ANALYSED = 'analysed';

	private const COMPILE_ERROR = 'compileError';

	private const EXPECTED_FAIL = 'expectedFail';

	private const PACKAGES = ['latte/latte', 'nette/application', 'nette/forms'];

	private const FAILURE_IDENTIFIERS = ['orisaiNette.latte.internalError', 'phpstan.parse'];

	private ?ScratchProject $project = null;

	protected function tearDown(): void
	{
		if ($this->project !== null) {
			$this->project->cleanup();
		}

		parent::tearDown();
	}

	public function testTemplateStatesMatchTheManifest(): void
	{
		$profile = self::profile();
		$corpusDir = dirname(__DIR__, 2) . '/var/corpus/templates/' . $profile;
		if (!is_dir($corpusDir)) {
			self::markTestSkipped(sprintf(
				'The %s corpus is not harvested; run make corpus-harvest%s.',
				$profile,
				$profile === 'default' ? '' : ' PROFILE=' . $profile,
			));
		}

		$versions = self::installedVersions();
		$source = Json::decode(FileSystem::read($corpusDir . '/manifest-source.json'), Json::FORCE_ARRAY);
		foreach (self::PACKAGES as $package) {
			$harvested = $source['packages'][$package]['version'] ?? null;
			if ($harvested !== $versions[$package]) {
				self::fail(sprintf(
					'The %s corpus was harvested from %s %s, installed is %s; run make corpus-harvest again.',
					$profile,
					$package,
					$harvested ?? 'none',
					$versions[$package],
				));
			}
		}

		/** @var array<string, array{expects: array{class: string|null}|null}> $templates */
		$templates = $source['templates'];
		[$actual, $failures] = $this->analyse($corpusDir, array_keys($templates));

		if ($failures !== []) {
			$lines = [];
			foreach ($failures as $name => $messages) {
				foreach (array_unique($messages) as $message) {
					$lines[] = $name . ': ' . $message;
				}
			}

			self::fail("Internal errors in the {$profile} corpus:\n" . implode("\n", $lines));
		}

		$manifestFile = __DIR__ . '/manifest.' . $profile . '.json';
		$manifest = is_file($manifestFile)
			? Json::decode(FileSystem::read($manifestFile), Json::FORCE_ARRAY)
			: ['templates' => []];
		/** @var array<string, string|array{expectedFail: string}> $recorded */
		$recorded = $manifest['templates'];

		if (getenv('CORPUS_MANIFEST_WRITE') === '1') {
			$this->writeManifest($manifestFile, $versions, $recorded, $actual, $templates);
			self::assertFileExists($manifestFile);

			return;
		}

		$versionDiff = [];
		foreach (self::PACKAGES as $package) {
			if (($manifest[$package] ?? null) !== $versions[$package]) {
				$versionDiff[] = sprintf(
					'%s: manifest %s, installed %s',
					$package,
					$manifest[$package] ?? 'none',
					$versions[$package],
				);
			}
		}

		self::assertSame(
			[],
			array_merge($versionDiff, self::stateDiff($recorded, $actual)),
			sprintf(
				'The %s corpus differs from %s; review and regenerate with make corpus-manifest.',
				$profile,
				$manifestFile,
			),
		);
	}

	private static function profile(): string
	{
		$vendorDir = VendorDirectory::name();

		return $vendorDir === 'vendor' ? 'default' : substr($vendorDir, strlen('vendor-'));
	}

	/**
	 * @return array<string, string>
	 */
	private static function installedVersions(): array
	{
		$installed = ProjectInstalledVersions::get();
		$versions = [];
		foreach (self::PACKAGES as $package) {
			$versions[$package] = $installed->getPrettyVersion($package) ?? 'none';
		}

		return $versions;
	}

	/**
	 * @param list<string> $names
	 * @return array{array<string, string>, array<string, list<string>>}
	 */
	private function analyse(string $corpusDir, array $names): array
	{
		$this->project = ScratchProject::create('corpus');
		FileSystem::copy($corpusDir, $this->project->path('templates'));

		$result = $this->project->analyse(
			[
				'fileExtensions' => ['php', 'latte'],
				'orisaiNette' => ['latte' => ['enabled' => true]],
			],
			['templates'],
			[],
			['--memory-limit=2G'],
		);

		$states = [];
		foreach ($names as $name) {
			$states[$name] = self::ANALYSED;
		}

		$failures = [];
		foreach ($result['errors'] as $error) {
			$failures['(run)'][] = $error;
		}

		$prefix = $this->project->path('templates') . '/';
		foreach ($result['messages'] as $message) {
			$name = strncmp($message['file'], $prefix, strlen($prefix)) === 0
				? (string) substr($message['file'], strlen($prefix))
				: $message['file'];

			if (!isset($states[$name]) || in_array($message['identifier'], self::FAILURE_IDENTIFIERS, true)) {
				$failures[$name][] = ($message['identifier'] ?? '(no identifier)') . ': ' . $message['message'];
			} elseif ($message['identifier'] === 'orisaiNette.latte.parseError') {
				$states[$name] = self::COMPILE_ERROR;
			}
		}

		return [$states, $failures];
	}

	/**
	 * @param array<string, string|array{expectedFail: string}> $recorded
	 * @param array<string, string> $actual
	 * @return list<string>
	 */
	private static function stateDiff(array $recorded, array $actual): array
	{
		$names = array_keys($recorded + $actual);
		sort($names);

		$diff = [];
		foreach ($names as $name) {
			$was = isset($recorded[$name]) ? self::describe($recorded[$name]) : null;
			$is = $actual[$name] ?? null;
			$wasState = isset($recorded[$name]) ? self::stateOf($recorded[$name]) : null;
			if ($wasState === $is) {
				continue;
			}

			$diff[] = sprintf('%s: %s -> %s', $name, $was ?? '(new)', $is ?? '(not harvested)');
		}

		return $diff;
	}

	/**
	 * @param string|array{expectedFail: string} $entry
	 */
	private static function stateOf($entry): string
	{
		return is_array($entry) ? self::COMPILE_ERROR : $entry;
	}

	/**
	 * @param string|array{expectedFail: string} $entry
	 */
	private static function describe($entry): string
	{
		return is_array($entry) ? self::EXPECTED_FAIL . ' (' . $entry[self::EXPECTED_FAIL] . ')' : $entry;
	}

	/**
	 * @param array<string, string> $versions
	 * @param array<string, string|array{expectedFail: string}> $recorded
	 * @param array<string, string> $actual
	 * @param array<string, array{expects: array{class: string|null}|null}> $templates
	 */
	private function writeManifest(
		string $manifestFile,
		array $versions,
		array $recorded,
		array $actual,
		array $templates
	): void
	{
		$entries = [];
		foreach ($actual as $name => $state) {
			$previous = $recorded[$name] ?? null;
			$entries[$name] = is_array($previous) && $state === self::COMPILE_ERROR ? $previous : $state;
		}

		ksort($entries);
		FileSystem::write($manifestFile, Json::encode($versions + ['templates' => $entries], Json::PRETTY) . "\n");

		$counts = [];
		$unexpectedlyAnalysed = [];
		$unassertedErrors = [];
		foreach ($entries as $name => $entry) {
			$state = is_array($entry) ? self::EXPECTED_FAIL : $entry;
			$counts[$state] = ($counts[$state] ?? 0) + 1;

			$class = $templates[$name]['expects']['class'] ?? null;
			$assertsCompileError = is_string($class)
				&& in_array($class, ['Latte\CompileException', 'CompileException'], true);
			if ($state === self::ANALYSED && $assertsCompileError) {
				$unexpectedlyAnalysed[] = $name;
			} elseif ($state === self::COMPILE_ERROR && !$assertsCompileError) {
				$unassertedErrors[] = $name;
			}
		}

		ksort($counts);
		$diff = self::stateDiff($recorded, $actual);
		$report = [sprintf('%s: %d templates', $manifestFile, count($entries))];
		foreach ($counts as $state => $count) {
			$report[] = sprintf('  %s: %d', $state, $count);
		}

		$report[] = sprintf('State diff (%d):', count($diff));
		$report = array_merge($report, $diff);
		$report[] = sprintf(
			'Analysed although upstream asserts a CompileException (%d):',
			count($unexpectedlyAnalysed),
		);
		$report = array_merge($report, $unexpectedlyAnalysed);
		$report[] = sprintf('compileError without an asserted CompileException (%d):', count($unassertedErrors));
		$report = array_merge($report, $unassertedErrors);

		fwrite(STDERR, "\n" . implode("\n", $report) . "\n");
	}

}
