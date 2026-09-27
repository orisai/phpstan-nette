<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Cache;

use function array_key_exists;
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
				$ipKey = (string) substr($file, strlen(self::FINGERPRINT_PREFIX));
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
