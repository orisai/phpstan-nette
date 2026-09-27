<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Forms\Invalidation;

use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;
use function array_merge;

/**
 * Group A - whole files appearing, disappearing and moving. PHPStan carries these on its own
 * channels (the new-file and deleted-file branches of ResultCacheManager::restore()), so these rows
 * are expected to have been green before the fix as well; they are in the matrix to show the hole
 * is specific to BODY-level facts rather than to cross-file Forms facts in general, and to catch a
 * fix that traded one of those channels away.
 */
final class FileLevelInvalidationTest extends FormsInvalidationMatrixCase
{

	private const EXTRA_DUMP_LINE = 10;

	private const MOVED_DUMP_LINE = 20;

	public function testFormBearingFileAdded(): void
	{
		$this->assertScenario('a1-file-added', [
			[
				'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
					'ScratchExtraControl.php',
					$this->extraControlSource(),
				),
				'expect' => array_merge($this->seedErrors(), [$this->extraDump('ScratchExtraControl.php')]),
			],
		]);
	}

	public function testFormBearingFileRemoved(): void
	{
		$this->assertScenario('a2-file-removed', [
			[
				'mutate' => static fn (InvalidationScenario $scenario) => $scenario->delete('ScratchExtraControl.php'),
				'expect' => $this->seedErrors(),
			],
		], fn (InvalidationScenario $scenario) => $scenario->write(
			'ScratchExtraControl.php',
			$this->extraControlSource(),
		), array_merge($this->seedErrors(), [$this->extraDump('ScratchExtraControl.php')]));
	}

	public function testFormBearingFileRenamed(): void
	{
		$this->assertScenario('a3-file-renamed', [
			[
				'mutate' => static fn (InvalidationScenario $scenario) => $scenario->rename(
					'ScratchExtraControl.php',
					'ScratchRenamedControl.php',
				),
				'expect' => array_merge($this->seedErrors(), [$this->extraDump('ScratchRenamedControl.php')]),
			],
		], fn (InvalidationScenario $scenario) => $scenario->write(
			'ScratchExtraControl.php',
			$this->extraControlSource(),
		), array_merge($this->seedErrors(), [$this->extraDump('ScratchExtraControl.php')]));
	}

	public function testClassMovedIntoAnotherExistingFile(): void
	{
		$this->assertScenario('a4-class-moved', [
			[
				'mutate' => function (InvalidationScenario $scenario): void {
					$scenario->delete('ScratchExtraControl.php');
					$scenario->write('ScratchUnrelated.php', $this->unrelatedWithExtraControlSource());
				},
				'expect' => array_merge(
					$this->seedErrors(),
					[$this->extraDump('ScratchUnrelated.php', self::MOVED_DUMP_LINE)],
				),
			],
		], fn (InvalidationScenario $scenario) => $scenario->write(
			'ScratchExtraControl.php',
			$this->extraControlSource(),
		), array_merge($this->seedErrors(), [$this->extraDump('ScratchExtraControl.php')]));
	}

	private function extraDump(string $file, int $line = self::EXTRA_DUMP_LINE): string
	{
		return $this->valuesDump($line, 'ctorField: string, extraField: string', $file);
	}

}
