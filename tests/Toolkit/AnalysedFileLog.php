<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use Nette\Utils\FileSystem;
use function array_keys;
use function explode;
use function is_file;
use function sort;
use function str_replace;

// Which files a spawned analysis really analysed: PHPStan's own -vv output gives the count only.
final class AnalysedFileLog
{

	private string $configPath;

	private string $logPath;

	public function __construct(string $configPath, string $workDir)
	{
		$this->configPath = $workDir . '/analysed-file-log-wrapper.neon';
		$this->logPath = $workDir . '/analysed-files.log';
		FileSystem::write(
			$this->configPath,
			"includes:\n\t- " . $configPath . "\n\t- " . __DIR__ . "/analysed-file-log.neon\n",
		);
		FileSystem::delete($this->logPath);
	}

	public function getConfigPath(): string
	{
		return $this->configPath;
	}

	/**
	 * @return array<string, string>
	 */
	public function env(): array
	{
		return [AnalysedFileLogRule::ENV => $this->logPath];
	}

	/**
	 * @return list<string> sorted, relative to $baseDir
	 */
	public function read(string $baseDir): array
	{
		if (!is_file($this->logPath)) {
			return [];
		}

		$files = [];
		foreach (explode("\n", FileSystem::read($this->logPath)) as $file) {
			if ($file !== '') {
				$files[str_replace($baseDir . '/', '', $file)] = true;
			}
		}

		$files = array_keys($files);
		sort($files);

		return $files;
	}

}
