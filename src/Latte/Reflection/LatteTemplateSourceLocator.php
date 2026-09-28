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

		$file = $this->getClassNameToFile()[strtolower($identifier->getName())] ?? null;
		if ($file === null) {
			return null;
		}

		return $this->repository->getOrCreate($file)->locateIdentifier($reflector, $identifier);
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
