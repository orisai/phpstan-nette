<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Compile;

final class CompileResult
{

	private ?string $phpSource;

	private string $className;

	/** @var array<Diagnostic> */
	private array $diagnostics;

	/**
	 * @param array<Diagnostic> $diagnostics
	 */
	private function __construct(?string $phpSource, string $className, array $diagnostics)
	{
		$this->phpSource = $phpSource;
		$this->className = $className;
		$this->diagnostics = $diagnostics;
	}

	/**
	 * @param array<Diagnostic> $diagnostics
	 */
	public static function success(string $phpSource, string $className, array $diagnostics): self
	{
		return new self($phpSource, $className, $diagnostics);
	}

	public static function failure(string $className, Diagnostic $parseError): self
	{
		return new self(null, $className, [$parseError]);
	}

	public function getPhpSource(): ?string
	{
		return $this->phpSource;
	}

	public function getClassName(): string
	{
		return $this->className;
	}

	/**
	 * @return array<Diagnostic>
	 */
	public function getDiagnostics(): array
	{
		return $this->diagnostics;
	}

}
