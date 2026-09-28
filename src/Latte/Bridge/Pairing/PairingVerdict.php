<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Bridge\Pairing;

use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Latte\Bridge\TemplateClassFact;

final class PairingVerdict
{

	private string $primaryClass;

	/** @var Certainty::* */
	private string $primaryCertainty;

	/** @var TemplateClassFact::CHANNEL_* */
	private string $primaryChannel;

	/** @var list<TemplateClassFact> */
	private array $candidates;

	/** @var list<SitePairing> */
	private array $sitePairings;

	/** @var list<PairingConflict> */
	private array $conflicts;

	/** @var list<PairingOpaque> */
	private array $opaques;

	/**
	 * @param Certainty::* $primaryCertainty
	 * @param TemplateClassFact::CHANNEL_* $primaryChannel
	 * @param list<TemplateClassFact> $candidates
	 * @param list<SitePairing> $sitePairings
	 * @param list<PairingConflict> $conflicts
	 * @param list<PairingOpaque> $opaques
	 */
	public function __construct(
		string $primaryClass,
		string $primaryCertainty,
		string $primaryChannel,
		array $candidates,
		array $sitePairings,
		array $conflicts,
		array $opaques
	)
	{
		$this->primaryClass = $primaryClass;
		$this->primaryCertainty = $primaryCertainty;
		$this->primaryChannel = $primaryChannel;
		$this->candidates = $candidates;
		$this->sitePairings = $sitePairings;
		$this->conflicts = $conflicts;
		$this->opaques = $opaques;
	}

	public function getPrimaryClass(): string
	{
		return $this->primaryClass;
	}

	/**
	 * @return Certainty::*
	 */
	public function getPrimaryCertainty(): string
	{
		return $this->primaryCertainty;
	}

	/**
	 * @return TemplateClassFact::CHANNEL_*
	 */
	public function getPrimaryChannel(): string
	{
		return $this->primaryChannel;
	}

	/**
	 * @return list<TemplateClassFact>
	 */
	public function getCandidates(): array
	{
		return $this->candidates;
	}

	/**
	 * @return list<SitePairing>
	 */
	public function getSitePairings(): array
	{
		return $this->sitePairings;
	}

	/**
	 * @return list<PairingConflict>
	 */
	public function getConflicts(): array
	{
		return $this->conflicts;
	}

	/**
	 * @return list<PairingOpaque>
	 */
	public function getOpaques(): array
	{
		return $this->opaques;
	}

}
