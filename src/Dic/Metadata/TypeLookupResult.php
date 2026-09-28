<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Dic\Metadata;

final class TypeLookupResult
{

	private bool $known;

	/** @var list<string> */
	private array $autowiredNames;

	/** @var list<string> */
	private array $allNames;

	/**
	 * @param list<string> $autowiredNames
	 * @param list<string> $allNames
	 */
	public function __construct(bool $known, array $autowiredNames, array $allNames)
	{
		$this->known = $known;
		$this->autowiredNames = $autowiredNames;
		$this->allNames = $allNames;
	}

	public function isKnown(): bool
	{
		return $this->known;
	}

	/**
	 * @return list<string>
	 */
	public function getAutowiredNames(): array
	{
		return $this->autowiredNames;
	}

	/**
	 * @return list<string>
	 */
	public function getAllNames(): array
	{
		return $this->allNames;
	}

}
