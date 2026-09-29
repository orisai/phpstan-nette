<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use FilesystemIterator;
use JsonException;
use Nette\Neon\Neon;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\Process;
use function array_map;
use function array_replace;
use function bin2hex;
use function dirname;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_string;
use function json_decode;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sprintf;
use function sys_get_temp_dir;
use function trim;
use function unlink;
use function usort;
use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

final class ScratchProject
{

	private string $root;

	private string $libraryRoot;

	private function __construct(string $root)
	{
		$this->root = $root;
		$this->libraryRoot = dirname(__DIR__, 2);
	}

	public static function create(string $name, ?string $root = null): self
	{
		$root ??= sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orisai-phpstan-nette';
		$leaf = $root . DIRECTORY_SEPARATOR . $name . '-' . bin2hex(random_bytes(6));
		self::makeDirectory($leaf);

		return new self($leaf);
	}

	public function path(string $relative): string
	{
		return $this->root . DIRECTORY_SEPARATOR . $relative;
	}

	public function write(string $relative, string $content): void
	{
		$path = $this->path($relative);
		self::makeDirectory(dirname($path));
		if (file_put_contents($path, $content) === false) {
			throw new RuntimeException(sprintf('Cannot write "%s".', $path));
		}
	}

	/**
	 * @param array<string, mixed> $neonParameters
	 * @param list<string> $paths
	 * @param array<string, mixed> $neonServices
	 * @return array{exitCode: int, messages: list<array{file: string, line: int, message: string, identifier: string|null}>, errors: list<string>, stderr: string}
	 */
	public function analyse(array $neonParameters, array $paths, array $neonServices = []): array
	{
		$parameters = array_replace(
			[
				'level' => 8,
				'paths' => array_map(fn (string $path): string => $this->path($path), $paths),
				'tmpDir' => $this->path('tmp'),
				'bootstrapFiles' => [$this->libraryRoot . '/tests/autoload.php'],
			],
			$neonParameters,
		);
		$config = $this->path('phpstan.neon');
		$neon = [
			'includes' => [$this->libraryRoot . '/extension.neon'],
			'parameters' => $parameters,
		];
		if ($neonServices !== []) {
			$neon['services'] = $neonServices;
		}

		$this->write('phpstan.neon', Neon::encode($neon, true));

		$process = new Process(
			[
				PHP_BINARY,
				$this->libraryRoot . '/' . VendorDirectory::name() . '/bin/phpstan',
				'analyse',
				'--error-format=json',
				'--no-progress',
				'-c',
				$config,
			],
			// The project's own root, as a real consumer runs it: Latte relativizes templates against the cwd.
			$this->path(''),
			// A dependency profile's COMPOSER=composer.<profile>.json must not reach the spawn: PHPStan
			// reads it as the project's composer file, which changes what shipmonk reports.
			['XDEBUG_MODE' => 'off', 'COMPOSER' => false],
			null,
			null,
		);
		$exitCode = $process->run();

		[$messages, $errors] = $this->parseOutput($process->getOutput());
		// A crashed PHPStan prints nothing decodable, which must not read as a clean run.
		if ($exitCode !== 0 && $messages === [] && $errors === []) {
			$errors[] = trim($process->getErrorOutput());
		}

		return [
			'exitCode' => $exitCode,
			'messages' => $messages,
			'errors' => $errors,
			'stderr' => $process->getErrorOutput(),
		];
	}

	public function cleanup(): void
	{
		if (!is_dir($this->root)) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach ($iterator as $file) {
			if ($file->isDir() && !$file->isLink()) {
				rmdir($file->getPathname());
			} else {
				unlink($file->getPathname());
			}
		}

		rmdir($this->root);
	}

	/**
	 * @return array{list<array{file: string, line: int, message: string, identifier: string|null}>, list<string>}
	 */
	private function parseOutput(string $stdout): array
	{
		try {
			$decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $exception) {
			$raw = trim($stdout);

			return [[], $raw === '' ? [] : [$raw]];
		}

		if (!is_array($decoded)) {
			return [[], [trim($stdout)]];
		}

		$messages = [];
		foreach ($decoded['files'] ?? [] as $file => $fileResult) {
			foreach ($fileResult['messages'] ?? [] as $message) {
				$messages[] = [
					'file' => (string) $file,
					'line' => (int) ($message['line'] ?? 0),
					'message' => (string) $message['message'],
					'identifier' => isset($message['identifier']) && is_string($message['identifier'])
						? $message['identifier']
						: null,
				];
			}
		}

		usort($messages, static fn (array $a, array $b): int => [$a['file'], $a['line'], $a['message']]
				<=> [$b['file'], $b['line'], $b['message']]);

		$errors = [];
		foreach ($decoded['errors'] ?? [] as $error) {
			$errors[] = (string) $error;
		}

		return [$messages, $errors];
	}

	private static function makeDirectory(string $path): void
	{
		if (is_dir($path)) {
			return;
		}

		// Level by level rather than mkdir(recursive): under paratest a sibling worker creating the
		// shared parent first makes the recursive call fail with the leaf still missing.
		$parent = dirname($path);
		if ($parent !== $path && !is_dir($parent)) {
			self::makeDirectory($parent);
		}

		if (!@mkdir($path, 0777) && !is_dir($path)) {
			throw new RuntimeException(sprintf('Cannot create directory "%s".', $path));
		}
	}

}
