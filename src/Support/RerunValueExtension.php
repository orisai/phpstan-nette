<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Support;

use PHPStan\Analyser\ResultCache\ResultCacheValueExtension;
use function bin2hex;
use function random_bytes;

// A value no two runs share. A CollectedDataNode rule that reads state outside its collected data
// (stores, indexes, files) or writes it tracks this, so the reuseCollectedDataRuleErrors toggle never
// replays its errors - or skips its side effects - after that state moved.
final class RerunValueExtension implements ResultCacheValueExtension
{

	private ?string $run = null;

	public function getValue(string $key): string
	{
		return $this->run ??= bin2hex(random_bytes(16));
	}

	public function keyToResultCache(string $key): string
	{
		return $key;
	}

	public function keyFromResultCache(string $storedKey): string
	{
		return $storedKey;
	}

}
