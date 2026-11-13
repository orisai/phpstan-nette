<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Cache;

use PHPStan\Analyser\DependencyTracker;
use Throwable;
use function array_key_exists;
use function array_keys;
use function array_pop;
use function is_file;
use function sha1_file;
use function strlen;
use function strncmp;
use function substr;

final class DependencyRecorder
{

	// A dependency key opening with this NUL-led sentinel is a contributor fingerprint, not a file path
	// (no filesystem path contains a NUL byte); the ipKey follows it. stillValid routes such a key to
	// the fingerprint validator instead of hashing it as a file.
	private const FINGERPRINT_PREFIX = "\0fingerprint\x1f";

	/** @var list<array<string, string>> */
	private array $frames = [];

	/** @var array<string, string|null> */
	private array $hashes = [];

	private ?FingerprintValidator $fingerprintValidator = null;

	public function setFingerprintValidator(FingerprintValidator $fingerprintValidator): void
	{
		$this->fingerprintValidator = $fingerprintValidator;
	}

	public static function fingerprintDependencyKey(string $ipKey): string
	{
		return self::FINGERPRINT_PREFIX . $ipKey;
	}

	public static function fingerprintIpKey(string $dependencyKey): ?string
	{
		if (strncmp($dependencyKey, self::FINGERPRINT_PREFIX, strlen(self::FINGERPRINT_PREFIX)) !== 0) {
			return null;
		}

		return (string) substr($dependencyKey, strlen(self::FINGERPRINT_PREFIX));
	}

	// A memo serving the value must replay these on every later read: a reader in another file depends
	// on what the first computation read.

	/**
	 * @template T
	 * @param callable(): T $compute
	 * @return array{0: T, 1: array<string, string>}
	 */
	public function capture(callable $compute): array
	{
		$this->beginFrame();

		try {
			$value = $compute();
		} catch (Throwable $e) {
			$this->endFrame();

			throw $e;
		}

		return [$value, $this->endFrame()];
	}

	public function beginFrame(): void
	{
		$this->frames[] = [];
	}

	public function hasActiveFrame(): bool
	{
		return $this->frames !== [];
	}

	/**
	 * Detach the whole frame stack so a nested computation records into a clean slate — its reads
	 * cannot reach the enclosing frames. The returned handle restores them via restoreFrames().
	 *
	 * @return list<array<string, string>>
	 */
	public function detachFrames(): array
	{
		$saved = $this->frames;
		$this->frames = [];

		return $saved;
	}

	/**
	 * @param list<array<string, string>> $saved
	 */
	public function restoreFrames(array $saved): void
	{
		$this->frames = $saved;
	}

	public function record(string $file): void
	{
		if ($this->frames === []) {
			return;
		}

		$hash = $this->hash($file);
		if ($hash === null) {
			return;
		}

		// All active frames, not just the innermost: a nested read is a dependency of every
		// enclosing shape too.
		foreach ($this->frames as $index => $_) {
			$this->frames[$index][$file] = $hash;
		}
	}

	/**
	 * Record a key's contributor fingerprint into every active frame. An entry answered inside a frame
	 * EMBEDS that answer, whose correctness depends on the key's contributor set (which sites register
	 * for it) — a set no per-file dependency captures, since a registration in a file created after the
	 * entry was written never appears among the recorded files. On the next run stillValid refolds the
	 * universe and re-derives the fingerprint; a moved set invalidates the entry.
	 */
	public function recordFingerprint(string $ipKey, string $fingerprint): void
	{
		if ($this->frames === []) {
			return;
		}

		$key = self::FINGERPRINT_PREFIX . $ipKey;
		foreach ($this->frames as $index => $_) {
			$this->frames[$index][$key] = $fingerprint;
		}
	}

	// The bridge between the store's records and PHPStan's result cache: whatever the computation
	// read becomes a dependency of the analysed file.

	/**
	 * @template T
	 * @param callable(): T $compute
	 * @return T
	 */
	public function track(DependencyTracker $tracker, callable $compute)
	{
		[$value, $dependencies] = $this->capture($compute);

		foreach (array_keys($dependencies) as $key) {
			$ipKey = self::fingerprintIpKey($key);
			$tracker->trackValueDependency(
				FormsValueExtension::class,
				$ipKey === null ? FormsValueExtension::sourceKey($key) : FormsValueExtension::contributorsKey($ipKey),
			);
		}

		return $value;
	}

	/**
	 * @return array<string, string>
	 */
	public function endFrame(): array
	{
		$frame = array_pop($this->frames);

		return $frame ?? [];
	}

	/**
	 * @param array<string, string> $dependencies
	 */
	public function replay(array $dependencies): void
	{
		if ($this->frames === []) {
			return;
		}

		foreach ($dependencies as $file => $hash) {
			foreach ($this->frames as $index => $_) {
				$this->frames[$index][$file] = $hash;
			}
		}
	}

	/**
	 * @param array<string, string> $dependencies
	 */
	public function stillValid(array $dependencies): bool
	{
		foreach ($dependencies as $file => $hash) {
			if (strncmp($file, self::FINGERPRINT_PREFIX, strlen(self::FINGERPRINT_PREFIX)) === 0) {
				// A contributor fingerprint: refold the universe and compare. A null current
				// fingerprint (universe unenumerable) or a missing validator leaves the set
				// unverifiable, so the embedding entry is treated as invalid (recompute) — sound.
				$ipKey = (string) self::fingerprintIpKey($file);
				if (
					$this->fingerprintValidator === null
					|| $this->fingerprintValidator->currentFingerprint($ipKey) !== $hash
				) {
					return false;
				}

				continue;
			}

			if ($this->hash($file) !== $hash) {
				return false;
			}
		}

		return true;
	}

	private function hash(string $file): ?string
	{
		if (array_key_exists($file, $this->hashes)) {
			return $this->hashes[$file];
		}

		$hash = is_file($file) ? sha1_file($file) : false;

		return $this->hashes[$file] = $hash === false ? null : $hash;
	}

}
