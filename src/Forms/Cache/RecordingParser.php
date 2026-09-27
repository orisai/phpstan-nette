<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Cache;

use PhpParser\Node\Stmt;
use PHPStan\Parser\Parser;
use function is_file;

final class RecordingParser implements Parser
{

	private Parser $delegate;

	private DependencyRecorder $recorder;

	public function __construct(Parser $delegate, DependencyRecorder $recorder)
	{
		$this->delegate = $delegate;
		$this->recorder = $recorder;
	}

	/**
	 * @return array<Stmt>
	 */
	public function parseFile(string $file): array
	{
		// A borrowed/virtual analysis scope (e.g. the end-of-run CollectedDataNode scope) reports a
		// non-file path; reading it would fatal in FileReader, so degrade to an empty AST — real
		// analysed files are always readable, so this never changes behaviour for them.
		if (!is_file($file)) {
			return [];
		}

		$this->recorder->record($file);

		return $this->delegate->parseFile($file);
	}

	/**
	 * @return array<Stmt>
	 */
	public function parseString(string $sourceCode): array
	{
		return $this->delegate->parseString($sourceCode);
	}

}
