<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Bridge\Pairing;

use OriPhpstan\Nette\Latte\Bridge\TemplateClassFact;

final class SitePairing
{

	private string $className;

	/** @var TemplateClassFact::CHANNEL_* */
	private string $channel;

	private int $line;

	/**
	 * @param TemplateClassFact::CHANNEL_* $channel
	 */
	public function __construct(string $className, string $channel, int $line)
	{
		$this->className = $className;
		$this->channel = $channel;
		$this->line = $line;
	}

	public function getClassName(): string
	{
		return $this->className;
	}

	/**
	 * @return TemplateClassFact::CHANNEL_*
	 */
	public function getChannel(): string
	{
		return $this->channel;
	}

	public function getLine(): int
	{
		return $this->line;
	}

}
