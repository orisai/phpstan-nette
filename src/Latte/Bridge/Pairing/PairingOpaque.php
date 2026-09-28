<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Bridge\Pairing;

use OriPhpstan\Nette\Latte\Bridge\TemplateClassFact;

final class PairingOpaque
{

	public const NO_SITE_LINE = 0;

	/** @var TemplateClassFact::CHANNEL_* */
	private string $channel;

	private int $line;

	/**
	 * @param TemplateClassFact::CHANNEL_* $channel
	 */
	public function __construct(string $channel, int $line)
	{
		$this->channel = $channel;
		$this->line = $line;
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
