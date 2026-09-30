<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Tools\Corpus;

final class ExtractedTemplate
{

	public string $content;

	public int $line;

	public int $callLine;

	public string $call;

	public ?string $loaderKey;

	public ?string $variable;

	/** @var array{assertion: string, class: string|null, message: string|null}|null */
	public ?array $expects;

	/**
	 * @param array{assertion: string, class: string|null, message: string|null}|null $expects
	 */
	public function __construct(
		string $content,
		int $line,
		int $callLine,
		string $call,
		?string $loaderKey,
		?string $variable,
		?array $expects
	)
	{
		$this->content = $content;
		$this->line = $line;
		$this->callLine = $callLine;
		$this->call = $call;
		$this->loaderKey = $loaderKey;
		$this->variable = $variable;
		$this->expects = $expects;
	}

}
