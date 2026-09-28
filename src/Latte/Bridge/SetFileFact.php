<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Bridge;

use OriPhpstan\Nette\Forms\Shape\Certainty;

final class SetFileFact
{

	public const KIND_LITERAL = 'literal';

	public const KIND_CONVENTION = 'convention';

	public const KIND_OPAQUE = 'opaque';

	/** @var self::KIND_* */
	private string $kind;

	private ?string $path;

	/** @var Certainty::* */
	private string $certainty;

	/** @var array{file: string, line: int} */
	private array $site;

	/** @var MutationFact::PHASE_* */
	private string $phase;

	/** @var MutationFact::EFFECTIVE_* */
	private string $effectiveness;

	/**
	 * @param self::KIND_* $kind
	 * @param Certainty::* $certainty
	 * @param array{file: string, line: int} $site
	 * @param MutationFact::PHASE_* $phase
	 * @param MutationFact::EFFECTIVE_* $effectiveness
	 */
	public function __construct(
		string $kind,
		?string $path,
		string $certainty,
		array $site,
		string $phase,
		string $effectiveness
	)
	{
		$this->kind = $kind;
		$this->path = $path;
		$this->certainty = $certainty;
		$this->site = $site;
		$this->phase = $phase;
		$this->effectiveness = $effectiveness;
	}

	/**
	 * @param array{kind: self::KIND_*, path: string|null, certainty: Certainty::*, site: array{file: string, line: int}, phase: MutationFact::PHASE_*, effectiveness: MutationFact::EFFECTIVE_*} $data
	 */
	public static function fromArray(array $data): self
	{
		return new self(
			$data['kind'],
			$data['path'],
			$data['certainty'],
			$data['site'],
			$data['phase'],
			$data['effectiveness'],
		);
	}

	/**
	 * @return self::KIND_*
	 */
	public function getKind(): string
	{
		return $this->kind;
	}

	public function getPath(): ?string
	{
		return $this->path;
	}

	/**
	 * @return Certainty::*
	 */
	public function getCertainty(): string
	{
		return $this->certainty;
	}

	/**
	 * @return array{file: string, line: int}
	 */
	public function getSite(): array
	{
		return $this->site;
	}

	/**
	 * @return MutationFact::PHASE_*
	 */
	public function getPhase(): string
	{
		return $this->phase;
	}

	/**
	 * @return MutationFact::EFFECTIVE_*
	 */
	public function getEffectiveness(): string
	{
		return $this->effectiveness;
	}

	/**
	 * @return array{kind: self::KIND_*, path: string|null, certainty: Certainty::*, site: array{file: string, line: int}, phase: MutationFact::PHASE_*, effectiveness: MutationFact::EFFECTIVE_*}
	 */
	public function toArray(): array
	{
		return [
			'kind' => $this->kind,
			'path' => $this->path,
			'certainty' => $this->certainty,
			'site' => $this->site,
			'phase' => $this->phase,
			'effectiveness' => $this->effectiveness,
		];
	}

}
