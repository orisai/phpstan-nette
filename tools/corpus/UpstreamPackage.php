<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Tools\Corpus;

use FilesystemIterator;
use Nette\Utils\FileSystem;
use RuntimeException;
use SplFileInfo;
use function basename;
use function escapeshellarg;
use function exec;
use function fnmatch;
use function implode;
use function is_dir;
use function ltrim;
use function preg_match;
use function sort;
use function sprintf;
use function uniqid;

final class UpstreamPackage
{

	public string $package;

	public string $repository;

	private ?string $testSubdirectoryPattern;

	private function __construct(string $package, string $repository, ?string $testSubdirectoryPattern)
	{
		$this->package = $package;
		$this->repository = $repository;
		$this->testSubdirectoryPattern = $testSubdirectoryPattern;
	}

	/**
	 * @return list<self>
	 */
	public static function all(): array
	{
		return [
			new self('latte/latte', 'https://github.com/nette/latte', null),
			new self('nette/application', 'https://github.com/nette/application', 'Bridges.Latte*'),
			new self('nette/forms', 'https://github.com/nette/forms', 'Forms.Latte*'),
		];
	}

	public static function tag(string $prettyVersion): string
	{
		if (preg_match('~^v?(\d+\.\d+\.\d+)$~', $prettyVersion, $match) !== 1) {
			throw new RuntimeException(sprintf('Cannot derive a release tag from version "%s".', $prettyVersion));
		}

		return 'v' . $match[1];
	}

	public function checkoutDirectory(string $corpusDirectory, string $prettyVersion): string
	{
		return $corpusDirectory . '/' . basename($this->package) . '-' . ltrim(self::tag($prettyVersion), 'v');
	}

	public function checkout(string $corpusDirectory, string $prettyVersion): string
	{
		$directory = $this->checkoutDirectory($corpusDirectory, $prettyVersion);
		if (is_dir($directory)) {
			return $directory;
		}

		$partial = $directory . '.partial-' . uniqid();
		$command = sprintf(
			'git clone --quiet --depth 1 --branch %s %s %s 2>&1',
			escapeshellarg(self::tag($prettyVersion)),
			escapeshellarg($this->repository),
			escapeshellarg($partial),
		);
		exec($command, $output, $exitCode);
		if ($exitCode !== 0) {
			FileSystem::delete($partial);

			throw new RuntimeException(sprintf(
				"Cloning %s at %s failed (exit code %d):\n%s",
				$this->repository,
				self::tag($prettyVersion),
				$exitCode,
				implode("\n", $output),
			));
		}

		FileSystem::rename($partial, $directory);

		return $directory;
	}

	/**
	 * @return list<string>
	 */
	public function testDirectories(string $checkoutDirectory, int $latteMajor): array
	{
		if ($this->testSubdirectoryPattern === null) {
			return ['tests'];
		}

		$directories = [];
		foreach (new FilesystemIterator($checkoutDirectory . '/tests') as $file) {
			if (
				!$file instanceof SplFileInfo
				|| !$file->isDir()
				|| !fnmatch($this->testSubdirectoryPattern, $file->getFilename())
				|| (
					preg_match('~Latte(\d+)$~', $file->getFilename(), $match) === 1
					&& (int) $match[1] !== $latteMajor
				)
			) {
				continue;
			}

			$directories[] = 'tests/' . $file->getFilename();
		}

		sort($directories);

		return $directories;
	}

}
