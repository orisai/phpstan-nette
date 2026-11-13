<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Cache;

use OriPhpstan\Nette\Support\BootstrapFilesLoader;
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

	private ?BootstrapFilesLoader $bootstrapFilesLoader;

	private ?string $identity = null;

	/**
	 * @param list<string> $catalogs
	 */
	public function __construct(
		array $catalogs,
		ReflectionProvider $reflectionProvider,
		?BootstrapFilesLoader $bootstrapFilesLoader = null
	)
	{
		$this->catalogs = $catalogs;
		$this->reflectionProvider = $reflectionProvider;
		$this->bootstrapFilesLoader = $bootstrapFilesLoader;
	}

	public function get(): string
	{
		if ($this->identity !== null) {
			return $this->identity;
		}

		if ($this->catalogs === []) {
			return $this->identity = '';
		}

		// The coordinator may open the store before PHPStan runs %bootstrapFiles%; a store directory named
		// without the catalog's file would be pruned by the workers, and theirs by the coordinator.
		if ($this->bootstrapFilesLoader !== null) {
			$this->bootstrapFilesLoader->load();
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
