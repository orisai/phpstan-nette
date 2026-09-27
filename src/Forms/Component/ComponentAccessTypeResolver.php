<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Component;

use Nette\ComponentModel\IComponent;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use OriPhpstan\Nette\Forms\Catalog\Stub\ControlAnnotationValueTypeReader;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Parser\Parser;
use PHPStan\Type\ExpressionTypeResolverExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use function array_values;
use function count;
use function spl_object_id;

final class ComponentAccessTypeResolver implements ExpressionTypeResolverExtension
{

	private ConfigurationGuard $guard;

	private bool $enabled;

	private Parser $parser;

	private ComponentClassResolver $resolver;

	/** @var array<string, true> */
	private array $resolving = [];

	/** @var array<string, list<Class_>> */
	private array $classes = [];

	private ?string $currentFile = null;

	/**
	 * @param list<string> $analysedPaths
	 */
	public function __construct(
		ConfigurationGuard $guard,
		bool $enabled,
		Parser $parser,
		Parser $richParser,
		FormShapeCache $cache,
		ControlAnnotationValueTypeReader $catalogReader,
		array $analysedPaths = []
	)
	{
		// PHPStan builds type extensions with the container, before the reflection provider knows the
		// analysed paths (and in the stub validator's container too), so validate on first use.
		$this->guard = $guard;
		$this->enabled = $enabled;
		$this->parser = $parser;
		$this->resolver = new ComponentClassResolver($cache, $catalogReader, $richParser, $analysedPaths);
	}

	public function getType(Expr $expr, Scope $scope): ?Type
	{
		$this->guard->validate();

		if (!$this->enabled) {
			return null;
		}

		$this->evictOnFileChange($scope->getFile());

		if (!$expr instanceof ArrayDimFetch || $expr->dim === null) {
			return null;
		}

		$names = $scope->getType($expr->dim)->getConstantStrings();
		if (count($names) !== 1) {
			return null;
		}

		$guardKey = $scope->getFile() . '|' . spl_object_id($expr);
		if (isset($this->resolving[$guardKey])) {
			return null;
		}

		$this->resolving[$guardKey] = true;
		try {
			// phpstan-nette compatibility — load-bearing, do not change to a DynamicMethodReturnTypeExtension:
			// PHPStan 2.1.x UNIONS the non-null results of ALL matching DynamicMethodReturnTypeExtensions, and
			// phpstan-nette's ComponentModelArrayAccessDynamicReturnTypeExtension already types $container['x'].
			// ExpressionTypeResolverExtension runs first and short-circuits on the first non-null. Re-entering
			// $scope->getType($expr) under the guard yields phpstan-nette/native's own answer; we act ONLY when
			// that is exactly bare Nette\ComponentModel\IComponent (phpstan-nette bailed = the real case), so we
			// cleanly OVERRIDE only there and otherwise DEFER (no regression to already-resolved components).
			$would = $scope->getType($expr);
			if (!$would->equals(new ObjectType(IComponent::class))) {
				return null;
			}

			$ownerClass = $this->ownerClass($scope);
			if ($ownerClass === null) {
				return null;
			}

			$var = $expr->var;
			if ($var instanceof ArrayDimFetch && $var->dim !== null) {
				$outer = $scope->getType($var->dim)->getConstantStrings();
				if (count($outer) !== 1) {
					return null;
				}

				$childClass = $this->resolver->resolveChildClass(
					$ownerClass,
					$outer[0]->getValue(),
					$names[0]->getValue(),
					$scope,
				);

				return $childClass === null ? null : new ObjectType($childClass);
			}

			$class = $this->resolver->resolveComponentClass($ownerClass, $names[0]->getValue(), $scope);
			if ($class !== null) {
				return new ObjectType($class);
			}

			$ownChild = $this->resolver->resolveOwnChildClass($ownerClass, $names[0]->getValue(), $scope);

			return $ownChild === null ? null : new ObjectType($ownChild);
		} finally {
			unset($this->resolving[$guardKey]);
		}
	}

	private function evictOnFileChange(string $file): void
	{
		if ($this->currentFile === $file) {
			return;
		}

		$this->currentFile = $file;
		$this->classes = [];
		$this->resolver->clear();
	}

	private function ownerClass(Scope $scope): ?Class_
	{
		if (!$scope->isInClass()) {
			return null;
		}

		$reflection = $scope->getClassReflection();
		$file = $scope->getFile();
		if (!isset($this->classes[$file])) {
			$this->classes[$file] = array_values((new NodeFinder())->findInstanceOf(
				$this->parser->parseFile($file),
				Class_::class,
			));
		}

		foreach ($this->classes[$file] as $class) {
			if (isset($class->namespacedName) && $class->namespacedName->toString() === $reflection->getName()) {
				return $class;
			}
		}

		return null;
	}

}
