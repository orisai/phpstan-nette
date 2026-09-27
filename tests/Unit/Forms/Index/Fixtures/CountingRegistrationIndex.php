<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

use PHPStan\File\FileFinder;
use OriPhpstan\Nette\Forms\Index\FileFactIndex;
use OriPhpstan\Nette\Forms\Index\RegistrationFact;
use OriPhpstan\Nette\Forms\Index\RegistrationIndex;

/**
 * Counts reverse-map queries per interprocedural key so a test can prove the resolver's per-key
 * memo (positive and negative) short-circuits a repeated resolution without re-querying the fold.
 */
final class CountingRegistrationIndex extends RegistrationIndex
{

	/** @var array<string, int> */
	private array $queryCounts = [];

	/**
	 * @return list<array{file: string, fact: RegistrationFact}>
	 */
	public function handlerSites(string $class, string $method, int $paramIdx): array
	{
		$this->bump($class, $method, $paramIdx);

		return parent::handlerSites($class, $method, $paramIdx);
	}

	/**
	 * @return list<array{file: string, fact: RegistrationFact}>
	 */
	public function passThroughEdgesInto(string $class, string $method, int $paramIdx): array
	{
		$this->bump($class, $method, $paramIdx);

		return parent::passThroughEdgesInto($class, $method, $paramIdx);
	}

	public function queryCount(string $class, string $method, int $paramIdx): int
	{
		return $this->queryCounts[$this->countKey($class, $method, $paramIdx)] ?? 0;
	}

	private function bump(string $class, string $method, int $paramIdx): void
	{
		$key = $this->countKey($class, $method, $paramIdx);
		$this->queryCounts[$key] = ($this->queryCounts[$key] ?? 0) + 1;
	}

	private function countKey(string $class, string $method, int $paramIdx): string
	{
		return $class . '::' . $method . '#' . $paramIdx;
	}

}
