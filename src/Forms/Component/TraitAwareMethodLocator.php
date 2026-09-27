<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Component;

use OriPhpstan\Nette\Forms\Support\BoundedMap;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeFinder;
use PHPStan\Parser\Parser;
use ReflectionMethod;
use function is_file;
use function strncmp;

final class TraitAwareMethodLocator
{

	private const METHODS_BY_LINE_CACHE_LIMIT = 64;

	private Parser $parser;

	private BoundedMap $methodsByLineCache;

	public function __construct(Parser $parser)
	{
		$this->parser = $parser;
		$this->methodsByLineCache = new BoundedMap(self::METHODS_BY_LINE_CACHE_LIMIT);
	}

	public function ownFile(ReflectionMethod $method): ?string
	{
		$file = $method->getFileName();
		if ($file === false || strncmp($file, 'phar://', 7) === 0 || !is_file($file)) {
			return null;
		}

		return $file;
	}

	public function locate(ReflectionMethod $method): ?ClassMethod
	{
		$file = $this->ownFile($method);
		if ($file === null) {
			return null;
		}

		$startLine = $method->getStartLine();
		if ($startLine === false) {
			return null;
		}

		return $this->methodsByLine($file)[$startLine] ?? null;
	}

	/**
	 * @return array<int, ClassMethod>
	 */
	private function methodsByLine(string $file): array
	{
		if ($this->methodsByLineCache->has($file)) {
			/** @var array<int, ClassMethod> $cached */
			$cached = $this->methodsByLineCache->get($file);

			return $cached;
		}

		$index = [];
		$ast = $this->parser->parseFile($file);
		$finder = new NodeFinder();
		foreach ($finder->findInstanceOf($ast, Class_::class) as $classLike) {
			foreach ($classLike->getMethods() as $classMethod) {
				$index[$classMethod->getStartLine()] = $classMethod;
			}
		}

		foreach ($finder->findInstanceOf($ast, Trait_::class) as $classLike) {
			foreach ($classLike->getMethods() as $classMethod) {
				$index[$classMethod->getStartLine()] = $classMethod;
			}
		}

		$this->methodsByLineCache->set($file, $index);

		return $index;
	}

}
