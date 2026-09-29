<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Parser;

use PHPStan\Command\BootstrapFilesRunner;
use function class_exists;
use function is_file;
use function spl_autoload_functions;

// PHPStan 2.2 defers %bootstrapFiles% until right before the analysis, but
// ResultCacheManager::restore() already parses every changed file to compare exported nodes. A
// .latte parse resolves {templateType} through runtime class_exists(), so a class made loadable by
// a bootstrap-registered autoloader is unknown in that early parse, and the memoized result (this
// process, or a worker forked from it) then disagrees with a cold run. require_once keeps the
// later PHPStan run of the same files a no-op.
final class BootstrapFilesLoader
{

	/** @var list<string> */
	private array $files;

	private bool $loaded = false;

	/**
	 * @param list<string> $files
	 */
	public function __construct(array $files)
	{
		$this->files = $files;
	}

	public function load(): void
	{
		if ($this->loaded) {
			return;
		}

		$this->loaded = true;

		$autoloadFunctionsBefore = spl_autoload_functions();
		foreach ($this->files as $file) {
			if (!is_file($file)) {
				continue;
			}

			(static function (string $file): void {
				require_once $file;
			})($file);
		}

		// @phpstan-ignore phpstanApi.classConstant
		if (class_exists(BootstrapFilesRunner::class)) {
			// @phpstan-ignore phpstanApi.method
			BootstrapFilesRunner::mergeNewAutoloadFunctions($autoloadFunctionsBefore);
		}
	}

}
