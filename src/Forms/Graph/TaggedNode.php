<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Graph;

final class TaggedNode
{

	public const ATTRIBUTE = 'formShapeTaggedNode';

	private string $nodeId;

	private string $operationKind;

	private StructuralContext $structuralContext;

	public function __construct(string $nodeId, string $operationKind, StructuralContext $structuralContext)
	{
		$this->nodeId = $nodeId;
		$this->operationKind = $operationKind;
		$this->structuralContext = $structuralContext;
	}

	public function getNodeId(): string
	{
		return $this->nodeId;
	}

	public function getOperationKind(): string
	{
		return $this->operationKind;
	}

	public function getStructuralContext(): StructuralContext
	{
		return $this->structuralContext;
	}

}
