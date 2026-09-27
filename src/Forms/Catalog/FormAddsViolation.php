<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Catalog;

/**
 * A reason one `@form-adds` occurrence was rejected, carried out of the reader so the rule can report
 * it. The reader drops the occurrence in the same breath, so nothing the model answers ever rests on
 * an invalid tag — but an invalid tag is always reported rather than silently doing nothing.
 */
final class FormAddsViolation
{

	private string $identifier;

	private string $message;

	public function __construct(string $identifier, string $message)
	{
		$this->identifier = $identifier;
		$this->message = $message;
	}

	public function getIdentifier(): string
	{
		return $this->identifier;
	}

	public function getMessage(): string
	{
		return $this->message;
	}

}
