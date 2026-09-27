<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Cache;

use PHPStan\Reflection\ReflectionProvider;
use function implode;
use function is_file;
use function sha1;
use function sha1_file;
use function sort;

final class CatalogIdentity
{

	/** @var list<string> */
	private array $catalogs;

	private ReflectionProvider $reflectionProvider;

	private ?string $identity = null;

	/**
	 * @param list<string> $catalogs
	 */
	public function __construct(array $catalogs, ReflectionProvider $reflectionProvider)
	{
		$this->catalogs = $catalogs;
		$this->reflectionProvider = $reflectionProvider;
	}

	public function get(): string
	{
		if ($this->identity !== null) {
			return $this->identity;
		}

		if ($this->catalogs === []) {
			return $this->identity = '';
		}

		$catalogs = $this->catalogs;
		sort($catalogs);
		$parts = [];
		foreach ($catalogs as $catalog) {
			$file = $this->reflectionProvider->hasClass($catalog)
				? $this->reflectionProvider->getClass($catalog)->getFileName()
				: null;
			$parts[] = $catalog . '=' . ($file !== null && is_file($file) ? (string) sha1_file($file) : '');
		}

		return $this->identity = sha1(implode('|', $parts));
	}

}
