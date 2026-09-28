<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Compile;

final class Diagnostic
{

	private string $identifier;

	private string $message;

	private int $latteLine;

	private ?string $tip;

	public function __construct(string $identifier, string $message, int $latteLine, ?string $tip = null)
	{
		$this->identifier = $identifier;
		$this->message = $message;
		$this->latteLine = $latteLine;
		$this->tip = $tip;
	}

	public function getIdentifier(): string
	{
		return $this->identifier;
	}

	public function getMessage(): string
	{
		return $this->message;
	}

	public function getLatteLine(): int
	{
		return $this->latteLine;
	}

	public function getTip(): ?string
	{
		return $this->tip;
	}

}
