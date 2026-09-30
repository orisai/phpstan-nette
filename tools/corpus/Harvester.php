<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Tools\Corpus;

use FilesystemIterator;
use Nette\Utils\FileSystem;
use SplFileInfo;
use function basename;
use function json_encode;
use function pathinfo;
use function preg_match;
use function sort;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PATHINFO_EXTENSION;

final class Harvester
{

	public const MANIFEST = 'manifest-source.json';

	private PhptTemplateExtractor $extractor;

	public function __construct(PhptTemplateExtractor $extractor)
	{
		$this->extractor = $extractor;
	}

	/**
	 * @param list<HarvestSource> $sources
	 * @return array<string, array{inline: int, files: int}>
	 */
	public function harvest(string $outputDirectory, array $sources): array
	{
		FileSystem::delete($outputDirectory);

		$counts = [];
		$packages = [];
		$templates = [];
		foreach ($sources as $source) {
			$prefix = basename($source->package);
			$inline = 0;
			$files = 0;
			foreach ($this->findFiles($source) as $relativePath) {
				$path = $source->checkoutDirectory . '/' . $relativePath;
				if (pathinfo($relativePath, PATHINFO_EXTENSION) === 'latte') {
					$output = $prefix . '/' . $relativePath;
					FileSystem::copy($path, $outputDirectory . '/' . $output);
					$templates[$output] = [
						'package' => $source->package,
						'source' => $relativePath,
						'kind' => 'file',
						'line' => 1,
						'call' => null,
						'callLine' => null,
						'loaderKey' => null,
						'variable' => null,
						'expects' => null,
					];
					$files++;

					continue;
				}

				foreach ($this->extractor->extract(FileSystem::read($path)) as $n => $template) {
					$output = $prefix . '/' . $relativePath . '#' . ($n + 1) . '.latte';
					FileSystem::write($outputDirectory . '/' . $output, $template->content);
					$templates[$output] = [
						'package' => $source->package,
						'source' => $relativePath,
						'kind' => $template->loaderKey === null ? 'inline' : 'loader',
						'line' => $template->line,
						'call' => $template->call,
						'callLine' => $template->callLine,
						'loaderKey' => $template->loaderKey,
						'variable' => $template->variable,
						'expects' => $template->expects,
					];
					$inline++;
				}
			}

			$counts[$source->package] = ['inline' => $inline, 'files' => $files];
			$packages[$source->package] = [
				'version' => $source->version,
				'checkout' => basename($source->checkoutDirectory),
				'testDirectories' => $source->testDirectories,
				'inline' => $inline,
				'files' => $files,
			];
		}

		FileSystem::write(
			$outputDirectory . '/' . self::MANIFEST,
			json_encode(
				['packages' => $packages, 'templates' => $templates],
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
			) . "\n",
		);

		return $counts;
	}

	/**
	 * @return list<string>
	 */
	private function findFiles(HarvestSource $source): array
	{
		$files = [];
		foreach ($source->testDirectories as $testDirectory) {
			$this->collectFiles($source->checkoutDirectory, $testDirectory, $files);
		}

		sort($files);

		return $files;
	}

	/**
	 * @param list<string> $files
	 */
	private function collectFiles(string $root, string $relativeDirectory, array &$files): void
	{
		foreach (new FilesystemIterator($root . '/' . $relativeDirectory) as $file) {
			if (!$file instanceof SplFileInfo) {
				continue;
			}

			$relativePath = $relativeDirectory . '/' . $file->getFilename();
			if ($file->isDir()) {
				$this->collectFiles($root, $relativePath, $files);
			} elseif ($this->isHarvested($relativePath)) {
				$files[] = $relativePath;
			}
		}
	}

	private function isHarvested(string $relativePath): bool
	{
		$extension = pathinfo($relativePath, PATHINFO_EXTENSION);

		return $extension === 'latte'
			|| ($extension === 'phpt' && preg_match('~\.nodes\.phpt$~', $relativePath) !== 1);
	}

}
