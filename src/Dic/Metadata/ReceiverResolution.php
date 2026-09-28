<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Dic\Metadata;

final class ReceiverResolution
{

	private bool $base;

	/** @var list<string> */
	private array $profiles;

	/**
	 * @param list<string> $profiles
	 */
	public function __construct(bool $base, array $profiles)
	{
		$this->base = $base;
		$this->profiles = $profiles;
	}

	public function isBase(): bool
	{
		return $this->base;
	}

	/**
	 * @return list<string>
	 */
	public function getProfiles(): array
	{
		return $this->profiles;
	}

}
