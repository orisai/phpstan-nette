<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Includes;

// One argument of an include-family tag as the installed Latte tokenizes it: a `name: expr` /
// `name => expr` pair, a spread, or a bare positional expression. A lone variable or literal is
// classified so the consumer never tokenizes the expression itself.
final class TagArgument
{

	public const LITERAL_INT = 'int';

	public const LITERAL_FLOAT = 'float';

	public const LITERAL_STRING = 'string';

	public const LITERAL_BOOL = 'bool';

	public const LITERAL_NULL = 'null';

	private ?string $name;

	private string $source;

	private bool $spread;

	private ?string $variable;

	/** @var self::LITERAL_*|null */
	private ?string $literalType;

	/**
	 * @param self::LITERAL_*|null $literalType
	 */
	public function __construct(
		?string $name,
		string $source,
		bool $spread,
		?string $variable = null,
		?string $literalType = null
	)
	{
		$this->name = $name;
		$this->source = $source;
		$this->spread = $spread;
		$this->variable = $variable;
		$this->literalType = $literalType;
	}

	public function getName(): ?string
	{
		return $this->name;
	}

	public function getSource(): string
	{
		return $this->source;
	}

	public function isSpread(): bool
	{
		return $this->spread;
	}

	public function getVariable(): ?string
	{
		return $this->variable;
	}

	/**
	 * @return self::LITERAL_*|null
	 */
	public function getLiteralType(): ?string
	{
		return $this->literalType;
	}

}
