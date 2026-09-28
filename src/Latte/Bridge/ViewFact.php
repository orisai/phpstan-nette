<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Bridge;

use OriPhpstan\Nette\Forms\Shape\Certainty;
use function array_map;
use function is_string;

final class ViewFact
{

	private string $name;

	/** @var Certainty::* */
	private string $certainty;

	/** @var list<array{file: string, line: int}> */
	private array $sites;

	/** @var list<MutationFact|string> */
	private array $sources;

	/**
	 * @param Certainty::* $certainty
	 * @param list<array{file: string, line: int}> $sites
	 * @param list<MutationFact|string> $sources
	 */
	public function __construct(string $name, string $certainty, array $sites, array $sources)
	{
		$this->name = $name;
		$this->certainty = $certainty;
		$this->sites = $sites;
		$this->sources = $sources;
	}

	/**
	 * @param array{name: string, certainty: Certainty::*, sites: list<array{file: string, line: int}>, sources: list<array{kind: MutationFact::KIND_*, phase: MutationFact::PHASE_*, effectiveness: MutationFact::EFFECTIVE_*, line: int, argument: string|null}|string>} $data
	 */
	public static function fromArray(array $data): self
	{
		$sources = array_map(
			static fn ($source) => is_string($source) ? $source : MutationFact::fromArray($source),
			$data['sources'],
		);

		return new self($data['name'], $data['certainty'], $data['sites'], $sources);
	}

	public function getName(): string
	{
		return $this->name;
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
	 * @return list<MutationFact|string>
	 */
	public function getSources(): array
	{
		return $this->sources;
	}

	/**
	 * @return array{name: string, certainty: Certainty::*, sites: list<array{file: string, line: int}>, sources: list<array{kind: MutationFact::KIND_*, phase: MutationFact::PHASE_*, effectiveness: MutationFact::EFFECTIVE_*, line: int, argument: string|null}|string>}
	 */
	public function toArray(): array
	{
		return [
			'name' => $this->name,
			'certainty' => $this->certainty,
			'sites' => $this->sites,
			'sources' => array_map(
				static fn ($source) => is_string($source) ? $source : $source->toArray(),
				$this->sources,
			),
		];
	}

}
