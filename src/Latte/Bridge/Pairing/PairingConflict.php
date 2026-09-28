<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Bridge\Pairing;

use OriPhpstan\Nette\Latte\Bridge\TemplateClassFact;

final class PairingConflict
{

	public const KIND_NARROWER_DECLARATION = 'narrowerDeclaration';

	public const KIND_NO_ANCESTRY = 'noAncestry';

	public const KIND_INTRA_CHANNEL = 'intraChannel';

	/** @var self::KIND_* */
	private string $kind;

	private string $declaredClass;

	private string $runtimeClass;

	/** @var list<TemplateClassFact::CHANNEL_*> */
	private array $channels;

	/** @var list<int> */
	private array $lines;

	/**
	 * @param self::KIND_* $kind
	 * @param list<TemplateClassFact::CHANNEL_*> $channels
	 * @param list<int> $lines
	 */
	public function __construct(
		string $kind,
		string $declaredClass,
		string $runtimeClass,
		array $channels,
		array $lines
	)
	{
		$this->kind = $kind;
		$this->declaredClass = $declaredClass;
		$this->runtimeClass = $runtimeClass;
		$this->channels = $channels;
		$this->lines = $lines;
	}

	/**
	 * @return self::KIND_*
	 */
	public function getKind(): string
	{
		return $this->kind;
	}

	public function getDeclaredClass(): string
	{
		return $this->declaredClass;
	}

	public function getRuntimeClass(): string
	{
		return $this->runtimeClass;
	}

	/**
	 * @return list<TemplateClassFact::CHANNEL_*>
	 */
	public function getChannels(): array
	{
		return $this->channels;
	}

	/**
	 * @return list<int>
	 */
	public function getLines(): array
	{
		return $this->lines;
	}

}
