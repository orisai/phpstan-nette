<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Reflection;

use OriPhpstan\Nette\Latte\Compile\TemplateClassName;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use PHPStan\BetterReflection\Identifier\Identifier;
use PHPStan\BetterReflection\Identifier\IdentifierType;
use PHPStan\BetterReflection\Reflection\Reflection;
use PHPStan\BetterReflection\Reflector\Reflector;
use PHPStan\BetterReflection\SourceLocator\Type\SourceLocator;
use PHPStan\Reflection\BetterReflection\SourceLocator\OptimizedSingleFileSourceLocatorRepository;
use function glob;
use function pathinfo;
use function strlen;
use function strncmp;
use function strtolower;
use const PATHINFO_FILENAME;

final class LatteTemplateSourceLocator implements SourceLocator
{

	private const CLASS_NAME_PREFIX = 'LatteTpl_';

	private const SLICE_CLASS_NAME_PREFIX = 'LatteSlice_';

	private const DISCOVERY_CLASS_NAME_PREFIX = 'LatteDiscovery_';

	private OptimizedSingleFileSourceLocatorRepository $repository;

	private LatteUniverse $universe;

	private string $sliceStoreDirPath;

	private ?string $discoveryStoreDirPath;

	private bool $enabled;

	/** @var array<string, string>|null */
	private ?array $classNameToFile = null;

	public function __construct(
		OptimizedSingleFileSourceLocatorRepository $repository,
		LatteUniverse $universe,
		string $sliceStoreDirPath,
		bool $enabled,
		?string $discoveryStoreDirPath = null
	)
	{
		$this->repository = $repository;
		$this->universe = $universe;
		$this->sliceStoreDirPath = $sliceStoreDirPath;
		$this->discoveryStoreDirPath = $discoveryStoreDirPath;
		$this->enabled = $enabled;
	}

	public function locateIdentifier(Reflector $reflector, Identifier $identifier): ?Reflection
	{
		if (!$this->enabled || !$identifier->isClass() || !self::looksLikeTemplateClass($identifier->getName())) {
			return null;
		}

		$file = $this->fileFor($identifier->getName());
		if ($file === null) {
			return null;
		}

		return $this->repository->getOrCreate($file)->locateIdentifier($reflector, $identifier);
	}

	// The store files are written while the run is under way (PreAnalysisIndexBuilder materializes
	// the discovery store before the analysis, the narrowing store grows with it), and a worker
	// forked after this memo was built inherits it, so a miss re-reads the stores before answering.
	// That only helps a name first asked after the store write: an earlier miss is memoised by
	// PHPStan's MemoizingReflector and inherited by forks as well.
	private function fileFor(string $className): ?string
	{
		$key = strtolower($className);
		$file = $this->getClassNameToFile()[$key] ?? null;
		if ($file !== null) {
			return $file;
		}

		$this->classNameToFile = null;

		return $this->getClassNameToFile()[$key] ?? null;
	}

	/**
	 * @return list<Reflection>
	 */
	public function locateIdentifiersByType(Reflector $reflector, IdentifierType $identifierType): array
	{
		if (!$this->enabled || !$identifierType->isClass()) {
			return [];
		}

		$reflections = [];
		foreach ($this->getClassNameToFile() as $className => $file) {
			$reflection = $this->repository->getOrCreate($file)->locateIdentifier(
				$reflector,
				new Identifier($className, $identifierType),
			);
			if ($reflection !== null) {
				$reflections[] = $reflection;
			}
		}

		return $reflections;
	}

	private static function looksLikeTemplateClass(string $className): bool
	{
		return strncmp($className, self::CLASS_NAME_PREFIX, strlen(self::CLASS_NAME_PREFIX)) === 0
			|| strncmp($className, self::SLICE_CLASS_NAME_PREFIX, strlen(self::SLICE_CLASS_NAME_PREFIX)) === 0
			|| strncmp($className, self::DISCOVERY_CLASS_NAME_PREFIX, strlen(self::DISCOVERY_CLASS_NAME_PREFIX)) === 0;
	}

	/**
	 * @return array<string, string>
	 */
	private function getClassNameToFile(): array
	{
		if ($this->classNameToFile === null) {
			$this->classNameToFile = $this->buildClassNameToFile();
		}

		return $this->classNameToFile;
	}

	/**
	 * @return array<string, string>
	 */
	private function buildClassNameToFile(): array
	{
		$map = [];
		foreach ($this->universe->files() as $file) {
			$className = TemplateClassName::forPath($this->universe->relativePath($file));
			$map[strtolower($className)] = $file;
		}

		foreach ($this->sliceFiles() as $file) {
			$className = pathinfo($file, PATHINFO_FILENAME);
			$map[strtolower($className)] = $file;
		}

		foreach ($this->discoveryFiles() as $file) {
			$className = pathinfo($file, PATHINFO_FILENAME);
			$map[strtolower($className)] = $file;
		}

		return $map;
	}

	// Slice files are named `<SliceClassName>.php` (SiteScopeStore's own write convention), so the
	// class name is derivable straight from the filename - no need to parse file contents.

	/**
	 * @return list<string>
	 */
	private function sliceFiles(): array
	{
		$files = glob($this->sliceStoreDirPath . '/' . self::SLICE_CLASS_NAME_PREFIX . '*.php');

		return $files === false ? [] : $files;
	}

	// Discovery store files follow the same filename-is-class-name convention
	// (DiscoveryStore's own write convention); the prefix glob never matches class-index.php.

	/**
	 * @return list<string>
	 */
	private function discoveryFiles(): array
	{
		if ($this->discoveryStoreDirPath === null) {
			return [];
		}

		$files = glob($this->discoveryStoreDirPath . '/' . self::DISCOVERY_CLASS_NAME_PREFIX . '*.php');

		return $files === false ? [] : $files;
	}

}
