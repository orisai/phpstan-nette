<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Analyzer;

use OriPhpstan\Nette\Forms\Graph\TaggedNode;
use PhpParser\Node;
use PHPStan\Analyser\Scope;

final class WalkContext
{

	private string $trackedName;

	private string $trackedClass;

	/** @var array<int, array{node: Node, stmt: Node, scope: Scope, tagged: TaggedNode}> */
	private array $recordsByNode;

	/** @var array<Node\Stmt> */
	private array $rootStmts;

	private Scope $scope;

	/**
	 * @param array<int, array{node: Node, stmt: Node, scope: Scope, tagged: TaggedNode}> $recordsByNode
	 * @param array<Node\Stmt> $rootStmts
	 */
	public function __construct(
		string $trackedName,
		string $trackedClass,
		array $recordsByNode,
		array $rootStmts,
		Scope $scope
	)
	{
		$this->trackedName = $trackedName;
		$this->trackedClass = $trackedClass;
		$this->recordsByNode = $recordsByNode;
		$this->rootStmts = $rootStmts;
		$this->scope = $scope;
	}

	/**
	 * The scope the walk was entered with. Nothing the walk decides may depend on it — the result is
	 * cached under the file and the function-like alone — so it is here only to be handed on to a
	 * followed callee's own walk as the record-carrying scope, exactly as the entry records carry it.
	 */
	public function getScope(): Scope
	{
		return $this->scope;
	}

	public function getTrackedName(): string
	{
		return $this->trackedName;
	}

	public function getTrackedClass(): string
	{
		return $this->trackedClass;
	}

	/**
	 * @return array<int, array{node: Node, stmt: Node, scope: Scope, tagged: TaggedNode}>
	 */
	public function getRecordsByNode(): array
	{
		return $this->recordsByNode;
	}

	/**
	 * @return array<Node\Stmt>
	 */
	public function getRootStmts(): array
	{
		return $this->rootStmts;
	}

}
