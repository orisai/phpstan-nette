<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Bridge\Discovery;

use function array_map;
use function ksort;
use function usort;
use const SORT_STRING;

/**
 * @phpstan-type ProbeShape array{path: string, kind: self::PROBE_*, result: bool|string}
 * @phpstan-type DiscoveryShape array{viewCandidates: array<array-key, list<CandidateShape>>, layoutCandidates: list<CandidateShape>, opaques: list<array{reason: string, line: int|null}>, existenceSet: list<ProbeShape>}
 * @phpstan-import-type CandidateShape from CandidatePath
 */
final class DiscoveryFact
{

	public const PROBE_DIR = 'dir';

	public const PROBE_FILE = 'file';

	// The formulas' reverse operation reads a directory LISTING, which no stat re-probe can
	// re-validate: its recorded result is TemplateDirectoryListing's digest, not a boolean.
	public const PROBE_LISTING = 'listing';

	// array-key, not string: PHP coerces a numeric view name (the 404/405 error views) to an int
	// key on the way in, so every consumer casts it back.
	/** @var array<array-key, list<CandidatePath>> */
	private array $viewCandidates;

	/** @var list<CandidatePath> */
	private array $layoutCandidates;

	/** @var list<array{reason: string, line: int|null}> */
	private array $opaques;

	// Absolute probed paths, each tagged with the probe kind that produced its result - the same
	// entries the PhpFactsCache envelope re-probes (by kind) at load time.
	/** @var list<ProbeShape> */
	private array $existenceSet;

	/**
	 * @param array<array-key, list<CandidatePath>> $viewCandidates
	 * @param list<CandidatePath> $layoutCandidates
	 * @param list<array{reason: string, line: int|null}> $opaques
	 * @param list<ProbeShape> $existenceSet
	 */
	public function __construct(array $viewCandidates, array $layoutCandidates, array $opaques, array $existenceSet)
	{
		ksort($viewCandidates, SORT_STRING);
		$this->viewCandidates = $viewCandidates;
		$this->layoutCandidates = $layoutCandidates;
		$this->opaques = $opaques;
		usort(
			$existenceSet,
			static fn (array $a, array $b): int => [$a['path'], $a['kind']] <=> [$b['path'], $b['kind']],
		);
		$this->existenceSet = $existenceSet;
	}

	/**
	 * @param DiscoveryShape $data
	 */
	public static function fromArray(array $data): self
	{
		$viewCandidates = [];
		foreach ($data['viewCandidates'] as $view => $candidates) {
			$viewCandidates[$view] = array_map(
				static fn (array $candidate): CandidatePath => CandidatePath::fromArray($candidate),
				$candidates,
			);
		}

		return new self(
			$viewCandidates,
			array_map(
				static fn (array $candidate): CandidatePath => CandidatePath::fromArray($candidate),
				$data['layoutCandidates'],
			),
			$data['opaques'],
			$data['existenceSet'],
		);
	}

	/**
	 * @return array<array-key, list<CandidatePath>>
	 */
	public function getViewCandidates(): array
	{
		return $this->viewCandidates;
	}

	/**
	 * @return list<CandidatePath>
	 */
	public function getLayoutCandidates(): array
	{
		return $this->layoutCandidates;
	}

	/**
	 * @return list<array{reason: string, line: int|null}>
	 */
	public function getOpaques(): array
	{
		return $this->opaques;
	}

	/**
	 * @return list<ProbeShape>
	 */
	public function getExistenceSet(): array
	{
		return $this->existenceSet;
	}

	/**
	 * @return DiscoveryShape
	 */
	public function toArray(): array
	{
		$viewCandidates = [];
		foreach ($this->viewCandidates as $view => $candidates) {
			$viewCandidates[$view] = array_map(
				static fn (CandidatePath $candidate): array => $candidate->toArray(),
				$candidates,
			);
		}

		return [
			'viewCandidates' => $viewCandidates,
			'layoutCandidates' => array_map(
				static fn (CandidatePath $candidate): array => $candidate->toArray(),
				$this->layoutCandidates,
			),
			'opaques' => $this->opaques,
			'existenceSet' => $this->existenceSet,
		];
	}

}
