<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Graph;

use OriPhpstan\Nette\Forms\Catalog\ControlValueResolution;

final class NodeContributionSummary
{

	public const OP_ADD = 'add';

	public const OP_REMOVE = 'remove';

	private string $nodeId;

	private string $op;

	private ?string $name;

	private bool $dynamicName;

	private ?ControlValueResolution $resolution;

	/** @var list<string> */
	private array $nodeUnknownReasons;

	/** @var list<self> */
	private array $additional;

	/**
	 * The further registrations one node makes: a repeated `@form-adds` is the only thing that
	 * produces any, every other node contributing exactly one.
	 *
	 * @param list<string> $nodeUnknownReasons
	 * @param list<self> $additional
	 */
	public function __construct(
		string $nodeId,
		string $op,
		?string $name,
		bool $dynamicName,
		?ControlValueResolution $resolution,
		array $nodeUnknownReasons,
		array $additional = []
	)
	{
		$this->nodeId = $nodeId;
		$this->op = $op;
		$this->name = $name;
		$this->dynamicName = $dynamicName;
		$this->resolution = $resolution;
		$this->nodeUnknownReasons = $nodeUnknownReasons;
		$this->additional = $additional;
	}

	public function getNodeId(): string
	{
		return $this->nodeId;
	}

	public function getOp(): string
	{
		return $this->op;
	}

	public function getName(): ?string
	{
		return $this->name;
	}

	public function hasDynamicName(): bool
	{
		return $this->dynamicName;
	}

	public function getResolution(): ?ControlValueResolution
	{
		return $this->resolution;
	}

	/** @return list<string> */
	public function getNodeUnknownReasons(): array
	{
		return $this->nodeUnknownReasons;
	}

	/** @return list<self> */
	public function getAdditional(): array
	{
		return $this->additional;
	}

}
