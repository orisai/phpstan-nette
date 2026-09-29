<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Parser;

use PHPStan\Command\BootstrapFilesRunner;
use PHPStan\DependencyInjection\Container;
use RuntimeException;
use Throwable;
use function get_class;
use function is_file;
use function method_exists;
use function spl_autoload_functions;
use function sprintf;

// PHPStan 2.2 defers %bootstrapFiles% until right before the analysis, but
// ResultCacheManager::restore() already parses every changed file to compare exported nodes. A
// .latte parse resolves {templateType} through runtime class_exists(), so a class made loadable by
// a bootstrap-registered autoloader is unknown in that early parse, and the memoized result (this
// process, or a worker forked from it) then disagrees with a cold run. require_once keeps the
// later PHPStan run of the same files a no-op. Without the runner's publishing hook the files are
// left to PHPStan: loading them here would register their autoloaders before PHPStan snapshots
// spl_autoload_functions(), and BetterReflection would never learn about them.
final class BootstrapFilesLoader
{

	private Container $container;

	/** @var list<string> */
	private array $files;

	/** @var (callable(list<callable(string): void>|false): void)|null */
	private $publishAutoloaders;

	private bool $loaded = false;

	/**
	 * @param list<string> $files
	 * @param (callable(list<callable(string): void>|false): void)|null $publishAutoloaders
	 */
	public function __construct(Container $container, array $files, ?callable $publishAutoloaders)
	{
		$this->container = $container;
		$this->files = $files;
		$this->publishAutoloaders = $publishAutoloaders;
	}

	/**
	 * @param list<string> $files
	 */
	public static function create(Container $container, array $files): self
	{
		// @phpstan-ignore phpstanApi.classConstant, function.alreadyNarrowedType
		$publish = method_exists(BootstrapFilesRunner::class, 'mergeNewAutoloadFunctions')
			// @phpstan-ignore phpstanApi.classConstant
			? [BootstrapFilesRunner::class, 'mergeNewAutoloadFunctions']
			: null;

		return new self($container, $files, $publish);
	}

	public function load(): void
	{
		$publish = $this->publishAutoloaders;
		if ($this->loaded || $publish === null) {
			return;
		}

		$this->loaded = true;

		$autoloadFunctionsBefore = spl_autoload_functions();
		$container = $this->container;
		foreach ($this->files as $file) {
			if (!is_file($file)) {
				continue;
			}

			try {
				// phpcs:ignore SlevomatCodingStandard.Functions.UnusedInheritedVariablePassedToClosure
				(static function (string $file) use ($container): void {
					require_once $file;
				})($file);
			} catch (Throwable $e) {
				throw new RuntimeException(sprintf(
					'%s thrown in %s on line %d while loading bootstrap file %s: %s',
					get_class($e),
					$e->getFile(),
					$e->getLine(),
					$file,
					$e->getMessage(),
				), 0, $e);
			}
		}

		$publish($autoloadFunctionsBefore);
	}

}
