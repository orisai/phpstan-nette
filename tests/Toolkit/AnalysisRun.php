<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use function implode;
use function preg_match;

// One spawned `phpstan analyse` run, reduced to the two things a cold-vs-warm invalidation scenario
// ever asks: the full `file:line :: identifier :: message` error SET (never an exit code, never a
// count) and the result cache's own verbosity line, which is what turns "did the fix stay granular"
// into an assertable number instead of a hope.
final class AnalysisRun
{

	private const RESTORED_PATTERN = '~Result cache restored[^.]*\. (\d+) files? will be reanalysed\.~';

	/** @var list<string> */
	private array $errors;

	private string $diagnostics;

	/**
	 * @param list<string> $errors
	 */
	public function __construct(array $errors, string $diagnostics)
	{
		$this->errors = $errors;
		$this->diagnostics = $diagnostics;
	}

	public function getErrorText(): string
	{
		return $this->errors === [] ? '(no errors)' : implode("\n", $this->errors);
	}

	public function getDiagnostics(): string
	{
		return $this->diagnostics;
	}

	// null means the run never restored a cache at all - a cold run, or a warm run whose whole cache
	// was thrown away (a changed `metadata do not match` salt is exactly that). Distinguishing the
	// two from "restored, 0 reanalysed" is the whole point: a coarse invalidation mechanism shows up
	// here as null or as a corpus-sized count, never as a wrong error set.
	public function getReanalysedFiles(): ?int
	{
		if (preg_match(self::RESTORED_PATTERN, $this->diagnostics, $matches) !== 1) {
			return null;
		}

		return (int) $matches[1];
	}

	public function isCacheRestored(): bool
	{
		return $this->getReanalysedFiles() !== null;
	}

	public function isSettled(): bool
	{
		return $this->getReanalysedFiles() === 0;
	}

}
