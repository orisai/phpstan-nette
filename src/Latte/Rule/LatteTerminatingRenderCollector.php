<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Rule;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Latte\Includes\FirstPartyPaths;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\MethodReturnStatementsNode;
use function strlen;
use function strncmp;

// The render-side half of the missing-template evidence gate. A render<View> hook whose body always
// terminates never returns to the dispatch that would consult formatTemplateFiles(), so its mere
// existence is no evidence that the view resolves a template file.
//
// Termination has TWO independent sources, and asking PHPStan's own isAlwaysTerminating() - rather
// than restating either one here - is what inherits both at once: the configured
// earlyTerminatingMethodCalls vocabulary (redirect(), sendJson(), terminate() and friends) AND a
// call to a method whose return type is a DECLARED never, in any spelling PHPStan resolves to one
// (native, @return / @phpstan-return never, no-return, never-return, never-returns). Declared only,
// matching PHPStan's own isExplicit() gate: consuming an inferred never would tie suppression to
// inference precision that shifts between releases.
//
// What is collected here may only REDUCE findings - it feeds orisai.nette.latte.templateMissing suppression and
// nothing else. It must never reach the orphan computation's liveness roots, where a renderer that
// always terminates would instead ORPHAN its template and push a live file toward deletion.
//
// Termination is a whole-body property with a Scope behind it, which is exactly what the scope-free
// per-class walk cannot compute; collected per method, it stays a file-local fact that re-aggregates
// every run.

/**
 * @implements Collector<MethodReturnStatementsNode, string>
 */
final class LatteTerminatingRenderCollector implements Collector
{

	private const RENDER_METHOD_PREFIX = 'render';

	private FirstPartyPaths $firstPartyPaths;

	private bool $enabled;

	private bool $discoveryStoreEnabled;

	/**
	 * @param list<string> $firstPartyPaths
	 */
	public function __construct(ConfigurationGuard $guard, array $firstPartyPaths)
	{
		$this->firstPartyPaths = new FirstPartyPaths($firstPartyPaths);
		$this->enabled = $guard->isLatteEnabled();
		$this->discoveryStoreEnabled = $guard->isLatteDiscoveryEnabled();
	}

	public function getNodeType(): string
	{
		return MethodReturnStatementsNode::class;
	}

	/**
	 * @param MethodReturnStatementsNode $node
	 * @return string|null
	 */
	public function processNode(Node $node, Scope $scope)
	{
		if (!$this->enabled || !$this->discoveryStoreEnabled) {
			return null;
		}

		$methodName = $node->getMethodName();
		if (strncmp($methodName, self::RENDER_METHOD_PREFIX, strlen(self::RENDER_METHOD_PREFIX)) !== 0) {
			return null;
		}

		// The same first-party boundary PhpRenderWalk uses: only a class inside it can ever be a
		// renderer, so anything outside would be collected data no consumer can ask about.
		if (!$this->firstPartyPaths->contains($scope->getFile())) {
			return null;
		}

		if (!$node->getStatementResult()->isAlwaysTerminating()) {
			return null;
		}

		return $node->getClassReflection()->getName() . '::' . $methodName;
	}

}
