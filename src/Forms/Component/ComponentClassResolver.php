<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Component;

use Nette\Forms\Container as NetteContainer;
use OriPhpstan\Nette\Forms\Analyzer\AnalyzerStackFactory;
use OriPhpstan\Nette\Forms\Analyzer\FormShapeAnalyzer;
use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use OriPhpstan\Nette\Forms\Catalog\Stub\ControlAnnotationValueTypeReader;
use OriPhpstan\Nette\Forms\Inference\EnclosingFunctionLikeLocator;
use OriPhpstan\Nette\Forms\Shape\ComponentPath;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Parser\Parser;
use PHPStan\Type\ObjectType;
use function is_string;
use function spl_object_id;

final class ComponentClassResolver
{

	private ComponentConstructionLocator $locator;

	private EnclosingFunctionLikeLocator $records;

	private FormShapeAnalyzer $analyzer;

	/** @var array<string, FormShape|false> */
	private array $shapes = [];

	/**
	 * @param list<string> $analysedPaths the config's declared paths, shared by every consumer of the
	 * one FormShapeCache (see AnalyzerStackFactory)
	 */
	public function __construct(
		FormShapeCache $cache,
		ControlAnnotationValueTypeReader $catalogReader,
		Parser $richParser,
		array $analysedPaths = []
	)
	{
		$this->locator = new ComponentConstructionLocator();
		$this->records = new EnclosingFunctionLikeLocator();
		$this->analyzer = AnalyzerStackFactory::build($catalogReader, $cache, $richParser, $analysedPaths);
	}

	public function clear(): void
	{
		$this->shapes = [];
	}

	public function resolveComponentClass(Class_ $ownerClass, string $componentName, Scope $scope): ?string
	{
		$shape = $this->shapeOf($ownerClass, $componentName, $scope);

		return $shape === null ? null : $shape->getClassName();
	}

	public function resolveChildClass(
		Class_ $ownerClass,
		string $componentName,
		string $childName,
		Scope $scope
	): ?string
	{
		$shape = $this->shapeOf($ownerClass, $componentName, $scope);

		return $shape === null ? null : $this->lookupChild($shape, $childName);
	}

	public function resolveOwnChildClass(Class_ $ownerClass, string $childName, Scope $scope): ?string
	{
		$shape = $this->ownShapeOf($ownerClass, $scope);

		return $shape === null ? null : $this->lookupChild($shape, $childName);
	}

	/**
	 * $childName is a component PATH, not necessarily a single name - $this['form']['a-b'] is the
	 * same runtime lookup as $this['form']['a']['b'], so the shared ComponentPath walk descends the
	 * intermediate segments before the leaf is read off a channel. A walk that cannot reach a leaf
	 * (or a leaf in neither channel) is null, which leaves the caller on the native stub's answer.
	 */
	private function lookupChild(FormShape $shape, string $childName): ?string
	{
		$walk = ComponentPath::walk($shape, ComponentPath::split($childName));
		if (!$walk->isLeaf()) {
			return null;
		}

		$leafShape = $walk->getShape();
		$leaf = $walk->getSegment();

		$containers = $leafShape->getContainers();
		if (isset($containers[$leaf])) {
			return $containers[$leaf]->getClassName();
		}

		$slots = $leafShape->getSlots();
		if (isset($slots[$leaf])) {
			return $slots[$leaf]->getControlClass();
		}

		return null;
	}

	private function shapeOf(Class_ $ownerClass, string $componentName, Scope $scope): ?FormShape
	{
		$key = (isset($ownerClass->namespacedName) ? $ownerClass->namespacedName->toString() : (string) spl_object_id(
			$ownerClass,
		)) . '#' . $componentName;
		if (!isset($this->shapes[$key])) {
			$this->shapes[$key] = $this->compute($ownerClass, $componentName, $scope) ?? false;
		}

		return $this->shapes[$key] === false ? null : $this->shapes[$key];
	}

	private function compute(Class_ $ownerClass, string $componentName, Scope $scope): ?FormShape
	{
		$factory = $this->locator->locate($ownerClass, $componentName);
		if ($factory === null) {
			return null;
		}

		$formExpr = $this->returnedExpr($factory);
		if ($formExpr === null) {
			return null;
		}

		$shape = $this->analyzer->analyzeFormValue(
			$formExpr,
			$factory,
			$this->records->taggedRecords($factory, $scope),
			$scope,
		);

		return $shape->getClassName() === null ? null : $shape;
	}

	private function ownShapeOf(Class_ $ownerClass, Scope $scope): ?FormShape
	{
		$key = (isset($ownerClass->namespacedName) ? $ownerClass->namespacedName->toString() : (string) spl_object_id(
			$ownerClass,
		)) . '#$self';
		if (!isset($this->shapes[$key])) {
			$this->shapes[$key] = $this->computeOwn($ownerClass, $scope) ?? false;
		}

		return $this->shapes[$key] === false ? null : $this->shapes[$key];
	}

	private function computeOwn(Class_ $ownerClass, Scope $scope): ?FormShape
	{
		$ctor = $ownerClass->getMethod('__construct');
		if ($ctor === null) {
			return null;
		}

		$thisExpr = new Variable('this');
		if (!(new ObjectType(NetteContainer::class))->isSuperTypeOf($scope->getType($thisExpr))->yes()) {
			return null;
		}

		$shape = $this->analyzer->analyzeFormValue(
			$thisExpr,
			$ctor,
			$this->records->taggedRecords($ctor, $scope),
			$scope,
		);

		return $shape->getClassName() === null ? null : $shape;
	}

	private function returnedExpr(FunctionLike $factory): ?Expr
	{
		$returns = (new NodeFinder())->findInstanceOf($factory->getStmts() ?? [], Return_::class);
		if ($returns === [] || $returns[0]->expr === null) {
			return null;
		}

		$expr = $returns[0]->expr;

		return $expr instanceof Variable && is_string($expr->name) ? $expr : null;
	}

}
