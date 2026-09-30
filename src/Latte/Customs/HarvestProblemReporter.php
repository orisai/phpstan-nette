<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Customs;

use OriPhpstan\Nette\Latte\Compile\Diagnostic;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use function implode;

// The harvest has no file of its own, so its problems land once, on the first template of the
// analysed universe (sorted) - the same file on every worker and every run.
final class HarvestProblemReporter
{

	public const IDENTIFIER = 'orisaiNette.latte.customsHarvest';

	private CustomsHarvester $harvester;

	private LatteUniverse $universe;

	public function __construct(CustomsHarvester $harvester, LatteUniverse $universe)
	{
		$this->harvester = $harvester;
		$this->universe = $universe;
	}

	/**
	 * @return list<Diagnostic>
	 */
	public function diagnosticsFor(string $relativePath): array
	{
		$problems = $this->harvester->harvest()->getSourceProblems();
		if ($problems === []) {
			return [];
		}

		$files = $this->universe->files();
		if ($files === [] || $this->universe->relativePath($files[0]) !== $relativePath) {
			return [];
		}

		return [
			new Diagnostic(
				self::IDENTIFIER,
				'Latte customs harvest: ' . implode('; ', $problems)
				. ' - its files are left out of the harvest salt, so edits there do not invalidate the analysis.',
				1,
			),
		];
	}

}
