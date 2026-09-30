<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Tools;

use FilesystemIterator;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Tools\Corpus\Harvester;
use OriPhpstan\Nette\Tools\Corpus\HarvestSource;
use OriPhpstan\Nette\Tools\Corpus\PhptTemplateExtractor;
use SplFileInfo;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function getmypid;
use function ksort;
use function ltrim;
use function sys_get_temp_dir;
use function uniqid;

final class HarvesterTest extends BaseTestCase
{

	private string $outputDirectory;

	protected function setUp(): void
	{
		parent::setUp();
		$this->outputDirectory = sys_get_temp_dir() . '/corpus-harvester-test-' . getmypid() . '-' . uniqid();
	}

	protected function tearDown(): void
	{
		FileSystem::delete($this->outputDirectory);
		parent::tearDown();
	}

	public function testHarvest(): void
	{
		FileSystem::write($this->outputDirectory . '/stale.latte', 'stale');

		$counts = (new Harvester(new PhptTemplateExtractor()))->harvest($this->outputDirectory, [
			new HarvestSource('latte/latte', 'v3.1.6', __DIR__ . '/Fixtures/checkout', ['tests']),
		]);

		self::assertSame(['latte/latte' => ['inline' => 12, 'files' => 1]], $counts);
		self::assertSame(
			[
				'latte/tests/calls.phpt#1.latte' => '<p>{=1}</p>',
				'latte/tests/calls.phpt#2.latte' => "<ul n:if=\"true\">\n\t<li>A</li>\n</ul>",
				'latte/tests/calls.phpt#3.latte' => '{foreach [1, 2] as $item}{$item}{/foreach}',
				'latte/tests/calls.phpt#4.latte' => '{if}',
				'latte/tests/calls.phpt#5.latte' => "{block content}\n{\$title}\n{/block}",
				'latte/tests/calls.phpt#6.latte' => '{include "b.latte"}',
				'latte/tests/calls.phpt#7.latte' => '{$b}',
				'latte/tests/loops.phpt#1.latte' => '{embed "embed"}{/embed}',
				'latte/tests/outcomes.phpt#1.latte' => '{foreach}',
				'latte/tests/outcomes.phpt#2.latte' => "{=1}\n",
				'latte/tests/outcomes.phpt#3.latte' => '{include "inc"}',
				'latte/tests/outcomes.phpt#4.latte' => '{sandbox "x"}',
				'latte/tests/templates/page.latte' => "{block content}\n\t<h1>{\$title}</h1>\n{/block}\n",
			],
			$this->readOutput(),
		);

		self::assertSame(
			FileSystem::read(__DIR__ . '/Fixtures/manifest-source.expected.json'),
			FileSystem::read($this->outputDirectory . '/manifest-source.json'),
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function readOutput(string $relativeDirectory = ''): array
	{
		$files = [];
		foreach (new FilesystemIterator($this->outputDirectory . $relativeDirectory) as $file) {
			if (!$file instanceof SplFileInfo || $file->getFilename() === 'manifest-source.json') {
				continue;
			}

			$relativePath = $relativeDirectory . '/' . $file->getFilename();
			$files += $file->isDir()
				? $this->readOutput($relativePath)
				: [ltrim($relativePath, '/') => FileSystem::read($file->getPathname())];
		}

		ksort($files);

		return $files;
	}

}
