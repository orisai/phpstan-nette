<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Dic\DeadCode;

use OriPhpstan\Nette\Support\PhpstanRuntimeStubs;
use PHPStan\Analyser\ResultCache\ResultCacheValueExtension;
use Throwable;
use function array_keys;
use function get_class;
use function implode;
use function sort;
use const SORT_STRING;

final class DicUsageValueExtension implements ResultCacheValueExtension
{

	private DicUsageProvider $provider;

	public function __construct(DicUsageProvider $provider)
	{
		$this->provider = $provider;
	}

	public function getValue(string $key): string
	{
		PhpstanRuntimeStubs::ensureLoaded();

		try {
			$methodNames = array_keys($this->provider->calledMethodNames($key));
		} catch (Throwable $e) {
			return 'unavailable:' . get_class($e);
		}

		sort($methodNames, SORT_STRING);

		return implode(',', $methodNames);
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
