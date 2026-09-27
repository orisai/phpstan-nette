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

/**
 * Wraps a real phpstan config in a per-process tmpDir override, so spawned phpstan
 * children never share a form-shape-cache directory with another paratest worker.
 */
final class IsolatedPhpstanConfig
{

	private string $configPath;

	private string $tmpDir;

	public function __construct(string $configPath, string $tmpDir)
	{
		$this->configPath = $configPath;
		$this->tmpDir = $tmpDir;
	}

	/**
	 * @param list<string> $paths a config-paths override (RegistrationIndex's universe source) for a
	 * caller that spawns phpstan over a directory the real config's own `paths` cannot know about
	 * ahead of time (e.g. a per-test temp-copied fixture universe). Empty leaves the real config's
	 * own declared paths in effect.
	 * @param string|null $tmpDir reuse a caller-owned directory instead of minting a fresh per-call
	 * one - the only way two separate create() calls (one per spawn in a warm/cold result-cache
	 * scenario) can share the same resultCachePath, which defaults under tmpDir.
	 * @param array<string, bool|int|string|list<string>> $extraParameters additional `parameters:`
	 * entries, keyed by the DOTTED path a NEON `%…%` reference would use (e.g.
	 * `orisaiNette.forms.enabled`) so a scenario can vary a namespaced NEON parameter
	 * between two spawns of the same corpus - every dotted key sharing a prefix is grouped under one
	 * nested block. Values are real PHP values, encoded by Neon::encode(): pass a bool for a flag, not
	 * the string 'true'.
	 */
	public static function create(
		string $realConfigPath,
		array $paths = [],
		?string $tmpDir = null,
		array $extraParameters = []
	): self
	{
		$tmpDir ??= sys_get_temp_dir() . '/forms-phpstan-' . getmypid() . '-' . uniqid('', true);
		FileSystem::createDir($tmpDir);

		$configPath = $tmpDir . '/wrapper.neon';
		$parameters = ['tmpDir' => $tmpDir];

		if ($paths !== []) {
			$parameters['paths!'] = $paths;
		}

		FileSystem::write(
			$configPath,
			Neon::encode(
				[
					'includes' => [$realConfigPath],
					'parameters' => array_merge($parameters, self::groupParameters($extraParameters)),
				],
				true,
			),
		);

		return new self($configPath, $tmpDir);
	}

	/**
	 * Folds a flat `dotted.path => value` map into nested `parameters:` entries - every key sharing a
	 * dotted prefix (e.g. `orisaiNette.forms.enabled`, `orisaiNette.forms.defaultContainerClass`) lands
	 * in one nested block, mirroring the extension's own namespaced parameter schema. A key with no
	 * dot stays a plain top-level entry.
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
