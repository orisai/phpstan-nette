<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte3;

use Latte\Compiler\Nodes\TemplateNode;
use Latte\Engine;
use OriPhpstan\Nette\Latte\Compile\Diagnostic;

// The outcome of one Latte3Compiler::parse(): either the engine that produced the tree (its
// passthrough registrations included) with the tree and the captured declarations, or the failure.
// The tree is the PRE-PASS tree until generate() runs the passes over it in place.
final class ParsedTemplate
{

	private ?Engine $engine;

	private ?TemplateNode $node;

	/** @var list<CapturedDeclaration> */
	private array $declarations;

	/** @var list<Diagnostic> */
	private array $diagnostics;

	private ?Diagnostic $failure;

	/**
	 * @param list<CapturedDeclaration> $declarations
	 * @param list<Diagnostic> $diagnostics
	 */
	private function __construct(
		?Engine $engine,
		?TemplateNode $node,
		array $declarations,
		array $diagnostics,
		?Diagnostic $failure
	)
	{
		$this->engine = $engine;
		$this->node = $node;
		$this->declarations = $declarations;
		$this->diagnostics = $diagnostics;
		$this->failure = $failure;
	}

	/**
	 * @param list<CapturedDeclaration> $declarations
	 * @param list<Diagnostic> $diagnostics
	 */
	public static function parsed(Engine $engine, TemplateNode $node, array $declarations, array $diagnostics): self
	{
		return new self($engine, $node, $declarations, $diagnostics, null);
	}

	public static function failed(Diagnostic $failure): self
	{
		return new self(null, null, [], [], $failure);
	}

	public function getEngine(): ?Engine
	{
		return $this->engine;
	}

	public function getNode(): ?TemplateNode
	{
		return $this->node;
	}

	/**
	 * @return list<CapturedDeclaration>
	 */
	public function getDeclarations(): array
	{
		return $this->declarations;
	}

	/**
	 * @return list<Diagnostic>
	 */
	public function getDiagnostics(): array
	{
		return $this->diagnostics;
	}

	public function getFailure(): ?Diagnostic
	{
		return $this->failure;
	}

}
