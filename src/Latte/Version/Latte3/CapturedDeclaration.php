<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte3;

// One {varType}, {templateType} or {parameters} entry as the analysis extension saw it at parse
// time; types and defaults are the template's own spelling, not Latte's whitespace-free print.
final class CapturedDeclaration
{

	public const VAR_TYPE = 'varType';

	public const TEMPLATE_TYPE = 'templateType';

	public const PARAMETER = 'parameter';

	/** @var self::* */
	private string $kind;

	private ?string $type;

	private ?string $variable;

	private ?string $default;

	private int $line;

	private bool $inHead;

	/**
	 * @param self::* $kind
	 */
	public function __construct(
		string $kind,
		?string $type,
		?string $variable,
		?string $default,
		int $line,
		bool $inHead
	)
	{
		$this->kind = $kind;
		$this->type = $type;
		$this->variable = $variable;
		$this->default = $default;
		$this->line = $line;
		$this->inHead = $inHead;
	}

	/**
	 * @return self::*
	 */
	public function getKind(): string
	{
		return $this->kind;
	}

	public function getType(): ?string
	{
		return $this->type;
	}

	public function getVariable(): ?string
	{
		return $this->variable;
	}

	public function getDefault(): ?string
	{
		return $this->default;
	}

	public function getLine(): int
	{
		return $this->line;
	}

	public function isInHead(): bool
	{
		return $this->inHead;
	}

}
