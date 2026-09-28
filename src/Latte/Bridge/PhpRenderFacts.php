<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Bridge;

use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryFact;
use function array_flip;
use function array_map;
use function array_unique;
use function array_values;
use function ksort;
use function serialize;
use function sha1;
use function sort;
use function strcmp;
use function usort;
use const SORT_STRING;

/**
 * @phpstan-type MutationArrayShape array{kind: MutationFact::KIND_*, phase: MutationFact::PHASE_*, effectiveness: MutationFact::EFFECTIVE_*, line: int, argument: string|null}
 * @phpstan-type FactsArrayShape array{assignments: array<string, array{typeString: string, certainty: Certainty::*, sites: list<array{file: string, line: int}>}>, setFileTargets: list<array{kind: SetFileFact::KIND_*, path: string|null, certainty: Certainty::*, site: array{file: string, line: int}, phase: MutationFact::PHASE_*, effectiveness: MutationFact::EFFECTIVE_*}>, templateClass: array{className: string, channel: TemplateClassFact::CHANNEL_*, certainty: Certainty::*, sites: list<int>}|null, renderSites: list<array{fileArgPresent: bool, literalPath: string|null, site: array{file: string, line: int}}>, readSet: list<string>, templateClassCandidates: list<array{className: string, channel: TemplateClassFact::CHANNEL_*, certainty: Certainty::*, sites: list<int>}>, views: array<string, array{name: string, certainty: Certainty::*, sites: list<array{file: string, line: int}>, sources: list<MutationArrayShape|string>}>, mutations: list<MutationArrayShape>, hasOpenViewSet: bool, discovery: DiscoveryShape|null, createTemplateControl: self::CONTROL_*|null}
 * @phpstan-import-type DiscoveryShape from DiscoveryFact
 */
final class PhpRenderFacts
{

	// The FIRST ARGUMENT every path to Nette\Bridges\ApplicationLatte\TemplateFactory::createTemplate()
	// ends up passing, joined over the whole class. It decides two of the factory's own injected
	// variables and nothing else: $control IS that argument, and $presenter is
	// $control->getPresenterIfExists(). SELF is the renderer's own instance (Control::createTemplate()
	// and Presenter::createTemplate() both pass $this, and a call site may pass it explicitly), NONE
	// is a standalone $factory->createTemplate() that passes no control at all, OTHER is an argument
	// this walk cannot resolve to either - including two call sites of the same class disagreeing.
	// Absent (null) means no createTemplate() path was observed at all, which is a different answer
	// again and must stay distinguishable from OTHER for no reason other than honesty: both deny the
	// variables, but only OTHER says something was seen.

	public const CONTROL_SELF = 'self';

	public const CONTROL_NONE = 'none';

	public const CONTROL_OTHER = 'other';

	/** @var array<string, AssignmentFact> */
	private array $assignments;

	/** @var list<SetFileFact> */
	private array $setFileTargets;

	private ?TemplateClassFact $templateClass;

	/** @var list<RenderSiteFact> */
	private array $renderSites;

	/** @var list<string> */
	private array $readSet;

	/** @var list<TemplateClassFact> */
	private array $templateClassCandidates;

	/** @var array<string, ViewFact> */
	private array $views;

	/** @var list<MutationFact> */
	private array $mutations;

	private bool $hasOpenViewSet;

	private ?DiscoveryFact $discovery;

	/** @var self::CONTROL_*|null */
	private ?string $createTemplateControl;

	/**
	 * @param array<string, AssignmentFact> $assignments
	 * @param list<SetFileFact> $setFileTargets
	 * @param list<RenderSiteFact> $renderSites
	 * @param list<string> $readSet
	 * @param list<TemplateClassFact> $templateClassCandidates
	 * @param array<string, ViewFact> $views
	 * @param list<MutationFact> $mutations
	 * @param self::CONTROL_*|null $createTemplateControl
	 */
	public function __construct(
		array $assignments,
		array $setFileTargets,
		?TemplateClassFact $templateClass,
		array $renderSites,
		array $readSet,
		array $templateClassCandidates = [],
		array $views = [],
		array $mutations = [],
		bool $hasOpenViewSet = false,
		?DiscoveryFact $discovery = null,
		?string $createTemplateControl = null
	)
	{
		ksort($assignments, SORT_STRING);
		$this->assignments = $assignments;
		$this->setFileTargets = self::sortBySite(
			$setFileTargets,
			static fn (SetFileFact $fact): array => $fact->getSite(),
		);
		$this->templateClass = $templateClass;
		$this->renderSites = self::sortBySite(
			$renderSites,
			static fn (RenderSiteFact $fact): array => $fact->getSite(),
		);

		$readSet = array_values(array_unique($readSet));
		sort($readSet, SORT_STRING);
		$this->readSet = $readSet;

		// Walk encounter order is already deterministic and IS the canonical order - never sorted.
		$this->templateClassCandidates = $templateClassCandidates;

		ksort($views, SORT_STRING);
		$this->views = $views;
		$this->mutations = self::sortMutationsByLifecycle($mutations);
		$this->hasOpenViewSet = $hasOpenViewSet;
		$this->discovery = $discovery;
		$this->createTemplateControl = $createTemplateControl;
	}

	public static function empty(): self
	{
		return new self([], [], null, [], []);
	}

	/**
	 * @param FactsArrayShape $data
	 */
	public static function fromArray(array $data): self
	{
		$assignments = [];
		foreach ($data['assignments'] as $var => $fact) {
			$assignments[$var] = AssignmentFact::fromArray($fact);
		}

		$setFileTargets = array_map(
			static fn (array $fact): SetFileFact => SetFileFact::fromArray($fact),
			$data['setFileTargets'],
		);

		$renderSites = array_map(
			static fn (array $fact): RenderSiteFact => RenderSiteFact::fromArray($fact),
			$data['renderSites'],
		);

		$templateClass = $data['templateClass'] === null ? null : TemplateClassFact::fromArray($data['templateClass']);

		$templateClassCandidates = array_map(
			static fn (array $fact): TemplateClassFact => TemplateClassFact::fromArray($fact),
			$data['templateClassCandidates'],
		);

		$views = [];
		foreach ($data['views'] as $name => $fact) {
			$views[$name] = ViewFact::fromArray($fact);
		}

		$mutations = array_map(
			static fn (array $fact): MutationFact => MutationFact::fromArray($fact),
			$data['mutations'],
		);

		return new self(
			$assignments,
			$setFileTargets,
			$templateClass,
			$renderSites,
			$data['readSet'],
			$templateClassCandidates,
			$views,
			$mutations,
			$data['hasOpenViewSet'],
			$data['discovery'] === null ? null : DiscoveryFact::fromArray($data['discovery']),
			$data['createTemplateControl'],
		);
	}

	/**
	 * @return array<string, AssignmentFact>
	 */
	public function getAssignments(): array
	{
		return $this->assignments;
	}

	/**
	 * @return list<SetFileFact>
	 */
	public function getSetFileTargets(): array
	{
		return $this->setFileTargets;
	}

	public function getTemplateClass(): ?TemplateClassFact
	{
		return $this->templateClass;
	}

	/**
	 * @return list<TemplateClassFact>
	 */
	public function getTemplateClassCandidates(): array
	{
		return $this->templateClassCandidates;
	}

	/**
	 * @return list<RenderSiteFact>
	 */
	public function getRenderSites(): array
	{
		return $this->renderSites;
	}

	/**
	 * @return list<string>
	 */
	public function getReadSet(): array
	{
		return $this->readSet;
	}

	/**
	 * @return array<string, ViewFact>
	 */
	public function getViews(): array
	{
		return $this->views;
	}

	/**
	 * @return list<MutationFact>
	 */
	public function getMutations(): array
	{
		return $this->mutations;
	}

	public function hasOpenViewSet(): bool
	{
		return $this->hasOpenViewSet;
	}

	public function getDiscovery(): ?DiscoveryFact
	{
		return $this->discovery;
	}

	/**
	 * @return self::CONTROL_*|null
	 */
	public function getCreateTemplateControl(): ?string
	{
		return $this->createTemplateControl;
	}

	public function getCanonicalHash(): string
	{
		return sha1(serialize($this->toArray()));
	}

	/**
	 * @return FactsArrayShape
	 */
	public function toArray(): array
	{
		$assignments = [];
		foreach ($this->assignments as $var => $fact) {
			$assignments[$var] = $fact->toArray();
		}

		$views = [];
		foreach ($this->views as $name => $fact) {
			$views[$name] = $fact->toArray();
		}

		return [
			'assignments' => $assignments,
			'setFileTargets' => array_map(
				static fn (SetFileFact $fact): array => $fact->toArray(),
				$this->setFileTargets,
			),
			'templateClass' => $this->templateClass === null ? null : $this->templateClass->toArray(),
			'renderSites' => array_map(static fn (RenderSiteFact $fact): array => $fact->toArray(), $this->renderSites),
			'readSet' => $this->readSet,
			'templateClassCandidates' => array_map(
				static fn (TemplateClassFact $fact): array => $fact->toArray(),
				$this->templateClassCandidates,
			),
			'views' => $views,
			'mutations' => array_map(static fn (MutationFact $fact): array => $fact->toArray(), $this->mutations),
			'hasOpenViewSet' => $this->hasOpenViewSet,
			'discovery' => $this->discovery === null ? null : $this->discovery->toArray(),
			'createTemplateControl' => $this->createTemplateControl,
		];
	}

	/**
	 * @template T of SetFileFact|RenderSiteFact
	 * @param list<T> $facts
	 * @param callable(T): array{file: string, line: int} $siteOf
	 * @return list<T>
	 */
	private static function sortBySite(array $facts, callable $siteOf): array
	{
		usort($facts, static function ($a, $b) use ($siteOf): int {
			$byFile = strcmp($siteOf($a)['file'], $siteOf($b)['file']);

			return $byFile !== 0 ? $byFile : $siteOf($a)['line'] <=> $siteOf($b)['line'];
		});

		return $facts;
	}

	/**
	 * @param list<MutationFact> $mutations
	 * @return list<MutationFact>
	 */
	private static function sortMutationsByLifecycle(array $mutations): array
	{
		// Every field participates in the comparison, so fully equal facts are the only ties -
		// interchangeable in the serialization, keeping the order canonical on PHP 7.4's
		// non-stable usort too.
		$phaseOrder = array_flip(MutationFact::LIFECYCLE_PHASE_ORDER);
		usort($mutations, static function (MutationFact $a, MutationFact $b) use ($phaseOrder): int {
			$byPhase = $phaseOrder[$a->getPhase()] <=> $phaseOrder[$b->getPhase()];
			if ($byPhase !== 0) {
				return $byPhase;
			}

			$byLine = $a->getLine() <=> $b->getLine();
			if ($byLine !== 0) {
				return $byLine;
			}

			$byKind = strcmp($a->getKind(), $b->getKind());
			if ($byKind !== 0) {
				return $byKind;
			}

			$byArgument = strcmp($a->getArgument() ?? '', $b->getArgument() ?? '');

			return $byArgument !== 0 ? $byArgument : strcmp($a->getEffectiveness(), $b->getEffectiveness());
		});

		return $mutations;
	}

}
