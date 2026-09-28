<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Cache;

use Nette\Utils\FileSystem;
use Throwable;
use function fclose;
use function flock;
use function fopen;
use function glob;
use function is_array;
use function is_dir;
use function is_file;
use function serialize;
use function sha1;
use function uniqid;
use function unserialize;
use const GLOB_ONLYDIR;
use const LOCK_EX;
use const LOCK_UN;

final class LatteAnalysisCache
{

	/** @var array<string, true> */
	private static array $prunedBaseDirectories = [];

	private string $directory;

	public function __construct(string $baseDirectory, ?string $codeVersion = null)
	{
		$this->directory = $baseDirectory . '/v' . ($codeVersion ?? LatteCodeVersion::get());

		// Content-addressed / manifest-keyed entries are self-validating, so no clearing —
		// only drop directories from older code versions, once, from the main process
		// (workers share this run's directory).
		$isWorker = ($_SERVER['argv'][1] ?? null) === 'worker';
		if (!$isWorker && !isset(self::$prunedBaseDirectories[$baseDirectory])) {
			self::$prunedBaseDirectories[$baseDirectory] = true;
			self::pruneOtherVersions($baseDirectory, $this->directory);
		}
	}

	/**
	 * @param callable(): array<mixed> $compute
	 * @return array<mixed>
	 */
	public function rememberByManifest(string $keyPrefix, string $manifest, callable $compute): array
	{
		$key = sha1('manifest|' . $keyPrefix . '|' . $manifest);

		$loaded = $this->readEntry($key);
		if ($loaded !== null) {
			return $loaded;
		}

		$lock = $this->acquireLock($key);
		try {
			// Re-read under the lock: a peer may have computed and written the entry while we waited.
			$loaded = $this->readEntry($key);
			if ($loaded !== null) {
				return $loaded;
			}

			$value = $compute();
			$this->writeEntry($key, $value);

			return $value;
		} finally {
			$this->releaseLock($lock);
		}
	}

	/**
	 * @param callable(): array<mixed> $compute
	 * @return array<mixed>
	 */
	public function rememberContentAddressed(string $contentHash, string $nodeId, callable $compute): array
	{
		$key = $this->contentAddressedKey($nodeId, $contentHash);

		$loaded = $this->readEntry($key);
		if ($loaded !== null) {
			return $loaded;
		}

		$value = $compute();
		$this->writeEntry($key, $value);

		return $value;
	}

	/**
	 * @return array<mixed>|null
	 */
	public function readContentAddressed(string $contentHash, string $nodeId): ?array
	{
		return $this->readEntry($this->contentAddressedKey($nodeId, $contentHash));
	}

	/**
	 * @param array<mixed> $value
	 */
	public function writeContentAddressed(string $contentHash, string $nodeId, array $value): void
	{
		$this->writeEntry($this->contentAddressedKey($nodeId, $contentHash), $value);
	}

	public function clear(): void
	{
		self::deleteDirectory($this->directory);
	}

	private function contentAddressedKey(string $nodeId, string $contentHash): string
	{
		return sha1('content|' . $nodeId . '|' . $contentHash);
	}

	/**
	 * @return array<mixed>|null
	 */
	private function readEntry(string $key): ?array
	{
		$cached = self::readFile($this->directory . '/' . $key . '.ser');
		if ($cached === null) {
			return null;
		}

		$entry = @unserialize($cached);
		if (!is_array($entry)) {
			return null;
		}

		return $entry;
	}

	/**
	 * @param array<mixed> $value
	 */
	private function writeEntry(string $key, array $value): void
	{
		try {
			$serialized = serialize($value);
		} catch (Throwable $e) {
			return;
		}

		$this->writeAtomic($key, $serialized);
	}

	private function writeAtomic(string $key, string $serialized): void
	{
		$path = $this->directory . '/' . $key . '.ser';
		$tmp = $this->directory . '/' . $key . '.' . uniqid('', true) . '.tmp';

		try {
			FileSystem::write($tmp, $serialized);
			FileSystem::rename($tmp, $path);
		} catch (Throwable $e) {
			// concurrent workers share the directory; tolerate a racing write/rename
		}
	}

	/**
	 * @return resource|null
	 */
	private function acquireLock(string $key)
	{
		try {
			FileSystem::createDir($this->directory);
		} catch (Throwable $e) {
			return null;
		}

		$handle = @fopen($this->directory . '/' . $key . '.lock', 'c');
		if ($handle === false) {
			return null;
		}

		@flock($handle, LOCK_EX);

		return $handle;
	}

	/**
	 * @param resource|null $handle
	 */
	private function releaseLock($handle): void
	{
		if ($handle === null) {
			return;
		}

		@flock($handle, LOCK_UN);
		@fclose($handle);
	}

	private static function readFile(string $path): ?string
	{
		if (!is_file($path)) {
			return null;
		}

		try {
			return FileSystem::read($path);
		} catch (Throwable $e) {
			return null;
		}
	}

	private static function pruneOtherVersions(string $baseDirectory, string $current): void
	{
		if (!is_dir($baseDirectory)) {
			return;
		}

		$matches = glob($baseDirectory . '/v*', GLOB_ONLYDIR);
		if ($matches === false) {
			return;
		}

		foreach ($matches as $directory) {
			if ($directory !== $current) {
				self::deleteDirectory($directory);
			}
		}
	}

	private static function deleteDirectory(string $directory): void
	{
		if (!is_dir($directory)) {
			return;
		}

		try {
			FileSystem::delete($directory);
		} catch (Throwable $e) {
			// best-effort; concurrent pruning by a sibling is harmless
		}
	}

}
