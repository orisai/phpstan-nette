<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Index;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use function is_string;

/**
 * Emits every method-param key that could carry a `forMethodParam` interprocedural shape, so the
 * end-of-run ShadowDivergenceRule can compare the collector store against the RegistrationIndex for
 * each one. The candidacy gate reproduces the pass-through grammar's syntactic test verbatim (any
 * class-Name-typed parameter, nullable unwrapped) — over-candidacy is deliberate: a key the store
 * and index both miss renders identically and reports nothing, so a superset only widens coverage.
 *
 * @implements Collector<ClassMethod, list<array{class: string, method: string, paramIdx: int, line: int}>>
 */
final class ShadowCandidateCollector implements Collector
{

	private bool $enabled;

	public function __construct(ConfigurationGuard $guard, bool $enabled)
	{
		$this->enabled = $guard->isFormsEnabled() && $enabled;
	}

	public function getNodeType(): string
	{
		return ClassMethod::class;
	}

	/**
	 * @param ClassMethod $node
	 * @return list<array{class: string, method: string, paramIdx: int, line: int}>|null
	 */
	public function processNode(Node $node, Scope $scope): ?array
	{
		if (!$this->enabled || !$scope->isInClass()) {
			return null;
		}

		$class = $scope->getClassReflection()->getName();
		$method = $node->name->toString();
		$line = $node->getStartLine();

		$candidates = [];
		foreach ($node->params as $idx => $param) {
			if (!$param->var instanceof Variable || !is_string($param->var->name)) {
				continue;
			}

			$type = $param->type;
			if ($type instanceof NullableType) {
				$type = $type->type;
			}

			if (!$type instanceof Name) {
				continue;
			}

			$candidates[] = [
				'class' => $class,
				'method' => $method,
				'paramIdx' => (int) $idx,
				'line' => $line,
			];
		}

		return $candidates === [] ? null : $candidates;
	}

}
