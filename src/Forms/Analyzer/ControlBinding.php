<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Analyzer;

use OriPhpstan\Nette\Forms\Graph\TaggedNode;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use function array_merge;

/**
 * What a local variable currently refers to in the walk's binding environment: the value
 * control bound by `$c = $form->addText('x')`, the add tagged-record so the slot can be
 * re-folded from the original add node, the separate-statement and Rules-variable modifier
 * chains gathered for it in source order, the Rules variables derived from it
 * (`$r = $c->addConditionOn(...)`) with the condition depth each starts at, and whether the
 * control has escaped the scope (so a later modifier opens its slot instead of refining it).
 */
final class ControlBinding
{

	/** @var array{node: Node, stmt: Node, scope: Scope, tagged: TaggedNode} */
	private array $record;

	/** @var list<array{tip: Node\Expr, startDepth: int, certainty: Certainty::*}> */
	private array $chains;

	/** @var array<string, int> */
	private array $rulesVars;

	private bool $escaped;

	/**
	 * @param array{node: Node, stmt: Node, scope: Scope, tagged: TaggedNode} $record
	 * @param list<array{tip: Node\Expr, startDepth: int, certainty: Certainty::*}> $chains
	 * @param array<string, int> $rulesVars
	 */
	public function __construct(array $record, array $chains = [], array $rulesVars = [], bool $escaped = false)
	{
		$this->record = $record;
		$this->chains = $chains;
		$this->rulesVars = $rulesVars;
		$this->escaped = $escaped;
	}

	/** @return array{node: Node, stmt: Node, scope: Scope, tagged: TaggedNode} */
	public function getRecord(): array
	{
		return $this->record;
	}

	/** @return list<array{tip: Node\Expr, startDepth: int, certainty: Certainty::*}> */
	public function getChains(): array
	{
		return $this->chains;
	}

	/** @return array<string, int> */
	public function getRulesVars(): array
	{
		return $this->rulesVars;
	}

	public function isEscaped(): bool
	{
		return $this->escaped;
	}

	/**
	 * @param array{tip: Node\Expr, startDepth: int, certainty: Certainty::*} $chain
	 */
	public function withChain(array $chain): self
	{
		return new self($this->record, array_merge($this->chains, [$chain]), $this->rulesVars, $this->escaped);
	}

	public function withRulesVar(string $name, int $depth): self
	{
		$rulesVars = $this->rulesVars;
		$rulesVars[$name] = $depth;

		return new self($this->record, $this->chains, $rulesVars, $this->escaped);
	}

	public function escape(): self
	{
		return new self($this->record, $this->chains, $this->rulesVars, true);
	}

}
