<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\LatteForms;

// One form scope opened in a template by {form X}, {formContext X} or <form n:name="X">, holding
// every control reference lexically inside it. Scopes nest, and a reference belongs to exactly one
// site - the innermost open one - mirroring vendor's runtime $this->global->formsStack.
final class FormSite
{

	private ?string $formName;

	private int $line;

	/** @var list<ControlReference> */
	private array $references;

	/**
	 * @param list<ControlReference> $references
	 */
	public function __construct(?string $formName, int $line, array $references)
	{
		$this->formName = $formName;
		$this->line = $line;
		$this->references = $references;
	}

	/**
	 * @param array{formName: string|null, line: int, references: list<array{kind: ControlReference::KIND_*, name: string|null, containerPath: list<string>, line: int, guarded: bool}>} $data
	 */
	public static function fromArray(array $data): self
	{
		$references = [];
		foreach ($data['references'] as $reference) {
			$references[] = ControlReference::fromArray($reference);
		}

		return new self($data['formName'], $data['line'], $references);
	}

	/**
	 * @return array{formName: string|null, line: int, references: list<array{kind: ControlReference::KIND_*, name: string|null, containerPath: list<string>, line: int, guarded: bool}>}
	 */
	public function toArray(): array
	{
		$references = [];
		foreach ($this->references as $reference) {
			$references[] = $reference->toArray();
		}

		return [
			'formName' => $this->formName,
			'line' => $this->line,
			'references' => $references,
		];
	}

	public function getFormName(): ?string
	{
		return $this->formName;
	}

	public function getLine(): int
	{
		return $this->line;
	}

	/**
	 * @return list<ControlReference>
	 */
	public function getReferences(): array
	{
		return $this->references;
	}

}
