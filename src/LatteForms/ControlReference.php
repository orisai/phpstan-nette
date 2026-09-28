<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\LatteForms;

// One syntactic reference to a control on the form opened by the enclosing FormSite. Purely
// lexical: nothing here is resolved against a PHP class, and getName() is null whenever the macro
// argument is not a component-name literal (a variable, an expression, a quoted non-name).
final class ControlReference
{

	public const KIND_INPUT = 'input';

	public const KIND_INPUT_ERROR = 'inputError';

	public const KIND_LABEL = 'label';

	public const KIND_NAME_ATTR = 'nameAttr';

	public const KIND_CONTAINER = 'container';

	/** @var self::KIND_* */
	private string $kind;

	private ?string $name;

	/** @var list<string> */
	private array $containerPath;

	private int $line;

	private bool $guarded;

	/**
	 * @param self::KIND_* $kind
	 * @param list<string> $containerPath
	 */
	public function __construct(string $kind, ?string $name, array $containerPath, int $line, bool $guarded = false)
	{
		$this->kind = $kind;
		$this->name = $name;
		$this->containerPath = $containerPath;
		$this->line = $line;
		$this->guarded = $guarded;
	}

	/**
	 * @param array{kind: self::KIND_*, name: string|null, containerPath: list<string>, line: int, guarded: bool} $data
	 */
	public static function fromArray(array $data): self
	{
		return new self($data['kind'], $data['name'], $data['containerPath'], $data['line'], $data['guarded']);
	}

	/**
	 * @return array{kind: self::KIND_*, name: string|null, containerPath: list<string>, line: int, guarded: bool}
	 */
	public function toArray(): array
	{
		return [
			'kind' => $this->kind,
			'name' => $this->name,
			'containerPath' => $this->containerPath,
			'line' => $this->line,
			'guarded' => $this->guarded,
		];
	}

	public function asGuarded(): self
	{
		return new self($this->kind, $this->name, $this->containerPath, $this->line, true);
	}

	/**
	 * @return self::KIND_*
	 */
	public function getKind(): string
	{
		return $this->kind;
	}

	// The name exactly as written, minus any ':'-separated control-part suffix. A '-' is NOT split
	// here: vendor Latte leaves it in the offset literal and Nette\ComponentModel\Container's own
	// getComponent() is what explodes it into a nested lookup at runtime, so whether `a-b` names one
	// control or walks into a container is a resolution question, not a syntactic one.
	public function getName(): ?string
	{
		return $this->name;
	}

	// The lexical {formContainer} / n:formContainer chain enclosing this reference, outermost
	// first. A reference inside a container whose own name is dynamic is never emitted at all -
	// the path could not express it - so every element here is a resolved literal.

	/**
	 * @return list<string>
	 */
	public function getContainerPath(): array
	{
		return $this->containerPath;
	}

	public function getLine(): int
	{
		return $this->line;
	}

	// Whether an existence check naming this same component encloses the reference -
	// n:ifset="$form['x']" / {ifset $form['x']}, which compile to the isset() the Forms extension's
	// own FormShapeUnknownAccessRule already exempts through ExistenceCheckMarkingNodeVisitor. The
	// guard is the author's declaration that presence is conditional, and offsetExists() throws for
	// nobody, so a consumer must not report a guarded reference.
	public function isGuarded(): bool
	{
		return $this->guarded;
	}

}
