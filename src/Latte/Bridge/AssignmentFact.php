<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Bridge;

use OriPhpstan\Nette\Forms\Shape\Certainty;
use function strcmp;
use function usort;

final class AssignmentFact
{

	private string $typeString;

	/** @var Certainty::* */
	private string $certainty;

	/** @var list<array{file: string, line: int}> */
	private array $sites;

	/**
	 * @param Certainty::* $certainty
	 * @param list<array{file: string, line: int}> $sites
	 */
	public function __construct(string $typeString, string $certainty, array $sites)
	{
		$this->typeString = $typeString;
		$this->certainty = $certainty;
		$this->sites = self::sortSites($sites);
	}

	/**
	 * @param array{typeString: string, certainty: Certainty::*, sites: list<array{file: string, line: int}>} $data
	 */
	public static function fromArray(array $data): self
	{
		return new self($data['typeString'], $data['certainty'], $data['sites']);
	}

	public function getTypeString(): string
	{
		return $this->typeString;
	}

	/**
	 * @return Certainty::*
	 */
	public function getCertainty(): string
	{
		return $this->certainty;
	}

	/**
	 * @return list<array{file: string, line: int}>
	 */
	public function getSites(): array
	{
		return $this->sites;
	}

	/**
	 * @return array{typeString: string, certainty: Certainty::*, sites: list<array{file: string, line: int}>}
	 */
	public function toArray(): array
	{
		return [
			'typeString' => $this->typeString,
			'certainty' => $this->certainty,
			'sites' => $this->sites,
		];
	}

	/**
	 * @param list<array{file: string, line: int}> $sites
	 * @return list<array{file: string, line: int}>
	 */
	private static function sortSites(array $sites): array
	{
		usort($sites, static function (array $a, array $b): int {
			$byFile = strcmp($a['file'], $b['file']);

			return $byFile !== 0 ? $byFile : $a['line'] <=> $b['line'];
		});

		return $sites;
	}

}
