<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use Nette\Neon\Neon;
use Nette\Utils\FileSystem;
use Throwable;
use function array_merge;
use function array_shift;
use function explode;
use function getmypid;
use function is_array;
use function sys_get_temp_dir;
use function uniqid;

// Per-PID tmpDir keeps the spawned phpstan child's result cache isolated under parallel paratest
// workers, mirroring IsolatedPhpstanConfig.
final class LattePhpstanConfig
{

	private string $configPath;

	private string $tmpDir;

	public function __construct(string $configPath, string $tmpDir)
	{
		$this->configPath = $configPath;
		$this->tmpDir = $tmpDir;
	}

	/**
	 * @param list<string> $paths analysed paths for this spawn - a directory (real-world `paths`
	 * shape, LatteTemplateSourceLocator resolves the LatteTpl_* classes it contains) or
	 * an explicit file list (phpstan's single-file reflection shortcut).
	 * @param string|null $tmpDir reuse a caller-owned directory instead of minting a fresh
	 * per-call one - the only way two separate create() calls (e.g. one per spawn in a
	 * result-cache warm/cold test) can share the same resultCachePath, which defaults under
	 * tmpDir.
	 * @param array<string, bool|int|string|list<string>> $extraParameters additional `parameters:`
	 * entries, keyed by the DOTTED path a NEON `%…%` reference would use (e.g.
	 * `orisai.nette.latte.discovery.enabled`) - every dotted key sharing a prefix is grouped under one nested
	 * block (later entries win on key collision, matching NEON's own semantics) - so callers must not
	 * repeat a base key here. Values are real PHP values, encoded by Neon::encode(): pass a bool for a
	 * flag, not the string 'true'. orisai.nette.latte.narrowing.storePath always defaults to a scratch path under
	 * $tmpDir; pass it explicitly here only to point a spawn at a real/shared store on purpose.
	 * @param list<string> $extraBootstrapFiles files appended to the included config's own
	 * bootstrapFiles - a scratch corpus whose classes must be class_exists()-visible (Latte's
	 * {templateType} resolution is a runtime autoload, not a ReflectionProvider read) has no other
	 * way in. Each such file must require the project autoloader itself: NEON list merging does not
	 * guarantee it runs after the included config's own entry.
	 */
	public static function create(
		string $realConfigPath,
		array $paths,
		?string $tmpDir = null,
		array $extraParameters = [],
		array $extraBootstrapFiles = []
	): self
	{
		$tmpDir ??= sys_get_temp_dir() . '/latte-phpstan-' . getmypid() . '-' . uniqid('', true);
		FileSystem::createDir($tmpDir);

		$configPath = $tmpDir . '/wrapper.neon';
		// Defaulted here (merged BEFORE $extraParameters, so an explicit override still wins on
		// NEON's own later-key-wins semantics) rather than left unset: an unset
		// orisai.nette.latte.narrowing.storePath silently inherits config/latte.neon's
		// %currentWorkingDirectory%-rooted default, so any spawn a future test author forgets to think
		// about the store for would read/write the REAL committed store instead of scratch - the
		// exact pollution bug a prior fix had to patch call-site by call-site. Safe by construction
		// beats safe by author diligence. Same guard for the discovery store, whose default sits in
		// the real %tmpDir%.
		$mergedParameters = array_merge(
			[
				'orisai.nette.latte.enabled' => true,
				'orisai.nette.latte.narrowing.storePath' => $tmpDir . '/sitescope-unused',
				'orisai.nette.latte.discovery.storePath' => $tmpDir . '/discovery-unused',
			],
			$extraParameters,
		);
		$parameters = [
			'tmpDir' => $tmpDir,
			'fileExtensions' => ['php', 'latte'],
			'paths!' => $paths,
		];

		if ($extraBootstrapFiles !== []) {
			$parameters['bootstrapFiles'] = $extraBootstrapFiles;
		}

		FileSystem::write(
			$configPath,
			Neon::encode(
				[
					'includes' => [$realConfigPath],
					'parameters' => array_merge($parameters, self::groupParameters($mergedParameters)),
				],
				true,
			),
		);

		return new self($configPath, $tmpDir);
	}

	/**
	 * Folds a flat `dotted.path => value` map into nested `parameters:` entries - every key sharing a
	 * dotted prefix (e.g. `orisai.nette.latte.discovery.enabled`, `orisai.nette.latte.discovery.storePath`)
	 * lands in one nested block, mirroring the extension's own namespaced parameter schema. A key with
	 * no dot stays a plain top-level entry.
	 *
	 * @param array<string, bool|int|string|list<string>> $parameters
	 * @return array<string, mixed>
	 */
	private static function groupParameters(array $parameters): array
	{
		$grouped = [];
		foreach ($parameters as $name => $value) {
			$grouped = self::nest($grouped, explode('.', $name), $value);
		}

		return $grouped;
	}

	/**
	 * @param array<string, mixed> $into
	 * @param list<string> $path
	 * @param bool|int|string|list<string> $value
	 * @return array<string, mixed>
	 */
	private static function nest(array $into, array $path, $value): array
	{
		$key = (string) array_shift($path);
		if ($path === []) {
			$into[$key] = $value;

			return $into;
		}

		$child = $into[$key] ?? [];
		$into[$key] = self::nest(is_array($child) ? $child : [], $path, $value);

		return $into;
	}

	public function getConfigPath(): string
	{
		return $this->configPath;
	}

	public function getTmpDir(): string
	{
		return $this->tmpDir;
	}

	public function cleanup(): void
	{
		try {
			FileSystem::delete($this->tmpDir);
		} catch (Throwable $exception) {
			// process-private dir; a stray handle in the just-exited child must not fail the test
		}
	}

}
