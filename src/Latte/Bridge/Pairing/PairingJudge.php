<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Bridge\Pairing;

use LogicException;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderFacts;
use OriPhpstan\Nette\Latte\Bridge\TemplateClassFact;
use PHPStan\Reflection\ReflectionProvider;
use function array_merge;
use function count;
use function in_array;

// Pure per-run component: verdicts reflect over candidate class hierarchies (files outside the
// walk read-set), so they are recomputed fresh every run and never persisted.
final class PairingJudge
{

	private const RUNTIME_CHANNELS = [
		TemplateClassFact::CHANNEL_CONVENTION,
		TemplateClassFact::CHANNEL_NEW,
	];

	private const DECLARATION_CHANNELS = [
		TemplateClassFact::CHANNEL_PHPDOC,
		TemplateClassFact::CHANNEL_GENERIC_BINDING,
	];

	private ReflectionProvider $reflectionProvider;

	public function __construct(ReflectionProvider $reflectionProvider)
	{
		$this->reflectionProvider = $reflectionProvider;
	}

	public function judge(PhpRenderFacts $facts): PairingVerdict
	{
		$candidates = $facts->getTemplateClassCandidates();

		/** @var list<TemplateClassFact> $classLevel */
		$classLevel = [];
		$sitePairings = [];
		$opaques = [];

		foreach ($candidates as $candidate) {
			if (!self::isClassLevelChannel($candidate->getChannel())) {
				// Channels outside both class-level lists pair one call site, never the class -
				// a per-site class differing from the primary is a legitimate secondary template.
				foreach ($candidate->getSites() as $line) {
					$sitePairings[] = new SitePairing($candidate->getClassName(), $candidate->getChannel(), $line);
				}

				continue;
			}

			$classLevel[] = $candidate;

			if (!$this->reflectionProvider->hasClass($candidate->getClassName())) {
				$lines = $candidate->getSites() === [] ? [PairingOpaque::NO_SITE_LINE] : $candidate->getSites();
				foreach ($lines as $line) {
					$opaques[] = new PairingOpaque($candidate->getChannel(), $line);
				}
			}
		}

		$conflicts = array_merge(
			$this->crossChannelConflicts($classLevel),
			$this->intraChannelConflicts($classLevel),
		);

		$primary = $this->resolvePrimary($classLevel, $facts);

		return new PairingVerdict(
			$primary->getClassName(),
			$primary->getCertainty(),
			$primary->getChannel(),
			$candidates,
			$sitePairings,
			$conflicts,
			$opaques,
		);
	}

	/**
	 * @param list<TemplateClassFact> $classLevel
	 * @return list<PairingConflict>
	 */
	private function crossChannelConflicts(array $classLevel): array
	{
		$conflicts = [];
		foreach ($classLevel as $declaration) {
			if (!in_array($declaration->getChannel(), self::DECLARATION_CHANNELS, true)) {
				continue;
			}

			foreach ($classLevel as $runtime) {
				if (!in_array($runtime->getChannel(), self::RUNTIME_CHANNELS, true)) {
					continue;
				}

				$conflict = $this->judgeAgreement($declaration, $runtime);
				if ($conflict !== null) {
					$conflicts[] = $conflict;
				}
			}
		}

		return $conflicts;
	}

	private function judgeAgreement(TemplateClassFact $declaration, TemplateClassFact $runtime): ?PairingConflict
	{
		$declaredName = $declaration->getClassName();
		$runtimeName = $runtime->getClassName();

		if ($declaredName === $runtimeName) {
			return null;
		}

		// Unknown class on either side: compatibility is OPEN, never a conflict.
		if (!$this->reflectionProvider->hasClass($declaredName) || !$this->reflectionProvider->hasClass($runtimeName)) {
			return null;
		}

		if ($this->reflectionProvider->getClass($runtimeName)->is($declaredName)) {
			return null;
		}

		$kind = $this->reflectionProvider->getClass($declaredName)->is($runtimeName)
			? PairingConflict::KIND_NARROWER_DECLARATION
			: PairingConflict::KIND_NO_ANCESTRY;

		return new PairingConflict(
			$kind,
			$declaredName,
			$runtimeName,
			[$declaration->getChannel(), $runtime->getChannel()],
			array_merge($declaration->getSites(), $runtime->getSites()),
		);
	}

	/**
	 * @param list<TemplateClassFact> $classLevel
	 * @return list<PairingConflict>
	 */
	private function intraChannelConflicts(array $classLevel): array
	{
		/** @var array<string, list<TemplateClassFact>> $byChannel */
		$byChannel = [];
		foreach ($classLevel as $candidate) {
			$byChannel[$candidate->getChannel()][] = $candidate;
		}

		$conflicts = [];
		foreach ($byChannel as $group) {
			$groupSize = count($group);
			for ($i = 0; $i < $groupSize; $i++) {
				for ($j = $i + 1; $j < $groupSize; $j++) {
					$first = $group[$i];
					$second = $group[$j];
					if ($first->getClassName() === $second->getClassName()) {
						continue;
					}

					if (
						!$this->reflectionProvider->hasClass($first->getClassName())
						|| !$this->reflectionProvider->hasClass($second->getClassName())
					) {
						continue;
					}

					$conflicts[] = new PairingConflict(
						PairingConflict::KIND_INTRA_CHANNEL,
						$first->getClassName(),
						$second->getClassName(),
						[$first->getChannel(), $second->getChannel()],
						array_merge($first->getSites(), $second->getSites()),
					);
				}
			}
		}

		return $conflicts;
	}

	/**
	 * @param list<TemplateClassFact> $classLevel
	 */
	private function resolvePrimary(array $classLevel, PhpRenderFacts $facts): TemplateClassFact
	{
		// Runtime-authoritative winner first (creation decides, the convention hook outranking a
		// createTemplate-override creation), declaration if no runtime channel, the facts'
		// ladder-resolved primary as the floor.
		foreach (self::RUNTIME_CHANNELS as $channel) {
			foreach ($classLevel as $candidate) {
				if ($candidate->getChannel() === $channel) {
					return $candidate;
				}
			}
		}

		foreach (self::DECLARATION_CHANNELS as $channel) {
			foreach ($classLevel as $candidate) {
				if ($candidate->getChannel() === $channel) {
					return $candidate;
				}
			}
		}

		$primary = $facts->getTemplateClass();
		if ($primary === null) {
			throw new LogicException('Pairing requires facts of a qualifying class with a resolved template class.');
		}

		return $primary;
	}

	private static function isClassLevelChannel(string $channel): bool
	{
		return in_array($channel, self::RUNTIME_CHANNELS, true)
			|| in_array($channel, self::DECLARATION_CHANNELS, true);
	}

}
