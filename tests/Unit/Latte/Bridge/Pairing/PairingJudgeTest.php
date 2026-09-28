<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing;

use Nette\Application\UI\Template as UiTemplate;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryResolver;
use OriPhpstan\Nette\Latte\Bridge\Pairing\PairingConflict;
use OriPhpstan\Nette\Latte\Bridge\Pairing\PairingJudge;
use OriPhpstan\Nette\Latte\Bridge\Pairing\PairingOpaque;
use OriPhpstan\Nette\Latte\Bridge\Pairing\PairingVerdict;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderFacts;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderWalk;
use OriPhpstan\Nette\Latte\Bridge\TemplateClassFact;
use OriPhpstan\Nette\Latte\Bridge\TemplateFactoryDefaultResolver;
use PHPStan\Parser\Parser;
use PHPStan\Testing\PHPStanTestCase;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingAgreeExactPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingBaseTemplateReplica;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingChildTemplateReplica;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingConventionAgreePresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingConventionDelegatingControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingConventionDriftControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingConventionDynamicControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingConventionIntraChannelControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingConventionNarrowerControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingDeadAncestorChildControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingDefaultsSilentPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingDualChannelControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingIntraChannelDriftPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingNarrowerDeclarationPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingNoAncestryPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingOverrideAssignFactoryPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingOverrideReturnFactoryPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingRuntimeNarrowerPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Pairing\Fixtures\App\PairingUnrelatedTemplateReplica;
use function glob;
use function realpath;
use function serialize;

final class PairingJudgeTest extends PHPStanTestCase
{

	// Deliberately a string literal: the point is a class name reflection cannot resolve, the
	// shape cache-loaded facts produce after a template class is deleted or renamed.
	private const VanishedTemplateClass = 'Tests\\OriPhpstan\\Nette\\Unit\\Latte\\Bridge\\Pairing\\Fixtures\\App\\PairingVanishedTemplateReplica';

	public function testAgreeingRuntimeAndDeclarationYieldTheRuntimePrimaryAndNoFindings(): void
	{
		$facts = $this->factsFor(PairingAgreeExactPresenter::class);
		$verdict = $this->judgeFacts($facts);

		self::assertSame(PairingBaseTemplateReplica::class, $verdict->getPrimaryClass());
		self::assertSame(TemplateClassFact::CHANNEL_NEW, $verdict->getPrimaryChannel());
		self::assertSame(Certainty::HAPPENS, $verdict->getPrimaryCertainty());
		self::assertCount(2, $facts->getTemplateClassCandidates());
		self::assertSame($facts->getTemplateClassCandidates(), $verdict->getCandidates());
		self::assertSame([], $verdict->getSitePairings());
		self::assertSame([], $verdict->getConflicts());
		self::assertSame([], $verdict->getOpaques());
	}

	public function testRuntimeSubtypeOfDeclarationIsCompatibleAndCreationDecidesThePrimary(): void
	{
		$verdict = $this->judgeFacts($this->factsFor(PairingRuntimeNarrowerPresenter::class));

		self::assertSame(PairingChildTemplateReplica::class, $verdict->getPrimaryClass());
		self::assertSame(TemplateClassFact::CHANNEL_NEW, $verdict->getPrimaryChannel());
		self::assertSame(Certainty::HAPPENS, $verdict->getPrimaryCertainty());
		self::assertSame([], $verdict->getConflicts());
		self::assertSame([], $verdict->getOpaques());
	}

	public function testDeclarationStrictSubtypeOfRuntimeConflictsPerDeclarationCandidate(): void
	{
		$verdict = $this->judgeFacts($this->factsFor(PairingNarrowerDeclarationPresenter::class));

		self::assertSame(PairingBaseTemplateReplica::class, $verdict->getPrimaryClass());
		self::assertSame(TemplateClassFact::CHANNEL_NEW, $verdict->getPrimaryChannel());
		self::assertSame(Certainty::HAPPENS, $verdict->getPrimaryCertainty());

		$conflicts = $verdict->getConflicts();
		self::assertCount(2, $conflicts);

		self::assertSame(PairingConflict::KIND_NARROWER_DECLARATION, $conflicts[0]->getKind());
		self::assertSame(PairingChildTemplateReplica::class, $conflicts[0]->getDeclaredClass());
		self::assertSame(PairingBaseTemplateReplica::class, $conflicts[0]->getRuntimeClass());
		self::assertSame(
			[TemplateClassFact::CHANNEL_PHPDOC, TemplateClassFact::CHANNEL_NEW],
			$conflicts[0]->getChannels(),
		);
		self::assertSame([13], $conflicts[0]->getLines());

		self::assertSame(PairingConflict::KIND_NARROWER_DECLARATION, $conflicts[1]->getKind());
		self::assertSame(PairingChildTemplateReplica::class, $conflicts[1]->getDeclaredClass());
		self::assertSame(PairingBaseTemplateReplica::class, $conflicts[1]->getRuntimeClass());
		self::assertSame(
			[TemplateClassFact::CHANNEL_GENERIC_BINDING, TemplateClassFact::CHANNEL_NEW],
			$conflicts[1]->getChannels(),
		);
		self::assertSame([13], $conflicts[1]->getLines());
	}

	public function testNoAncestryInEitherDirectionConflicts(): void
	{
		$verdict = $this->judgeFacts($this->factsFor(PairingNoAncestryPresenter::class));

		self::assertSame(PairingUnrelatedTemplateReplica::class, $verdict->getPrimaryClass());
		self::assertSame(TemplateClassFact::CHANNEL_NEW, $verdict->getPrimaryChannel());

		$conflicts = $verdict->getConflicts();
		self::assertCount(2, $conflicts);

		self::assertSame(PairingConflict::KIND_NO_ANCESTRY, $conflicts[0]->getKind());
		self::assertSame(PairingChildTemplateReplica::class, $conflicts[0]->getDeclaredClass());
		self::assertSame(PairingUnrelatedTemplateReplica::class, $conflicts[0]->getRuntimeClass());
		self::assertSame(
			[TemplateClassFact::CHANNEL_PHPDOC, TemplateClassFact::CHANNEL_NEW],
			$conflicts[0]->getChannels(),
		);
		self::assertSame([13], $conflicts[0]->getLines());

		self::assertSame(PairingConflict::KIND_NO_ANCESTRY, $conflicts[1]->getKind());
		self::assertSame(
			[TemplateClassFact::CHANNEL_GENERIC_BINDING, TemplateClassFact::CHANNEL_NEW],
			$conflicts[1]->getChannels(),
		);
	}

	public function testTwoDifferentClassesInsideOneClassLevelChannelConflict(): void
	{
		$verdict = $this->judgeFacts($this->factsFor(PairingIntraChannelDriftPresenter::class));

		self::assertSame(PairingBaseTemplateReplica::class, $verdict->getPrimaryClass());
		self::assertSame(TemplateClassFact::CHANNEL_NEW, $verdict->getPrimaryChannel());
		self::assertSame(Certainty::MAYBE, $verdict->getPrimaryCertainty());

		$conflicts = $verdict->getConflicts();
		self::assertCount(1, $conflicts);
		self::assertSame(PairingConflict::KIND_INTRA_CHANNEL, $conflicts[0]->getKind());
		self::assertSame(PairingBaseTemplateReplica::class, $conflicts[0]->getDeclaredClass());
		self::assertSame(PairingUnrelatedTemplateReplica::class, $conflicts[0]->getRuntimeClass());
		self::assertSame(
			[TemplateClassFact::CHANNEL_NEW, TemplateClassFact::CHANNEL_NEW],
			$conflicts[0]->getChannels(),
		);
		self::assertSame([16, 19], $conflicts[0]->getLines());

		self::assertSame([], $verdict->getSitePairings());
		self::assertSame([], $verdict->getOpaques());
	}

	public function testUnresolvableClassLevelCandidateIsOpaqueNotAConflict(): void
	{
		$fact = new TemplateClassFact(
			self::VanishedTemplateClass,
			TemplateClassFact::CHANNEL_NEW,
			Certainty::HAPPENS,
			[12],
		);
		$verdict = $this->judgeFacts(new PhpRenderFacts([], [], $fact, [], [], [$fact]));

		self::assertSame([], $verdict->getConflicts());

		$opaques = $verdict->getOpaques();
		self::assertCount(1, $opaques);
		self::assertSame(TemplateClassFact::CHANNEL_NEW, $opaques[0]->getChannel());
		self::assertSame(12, $opaques[0]->getLine());

		self::assertSame(self::VanishedTemplateClass, $verdict->getPrimaryClass());
		self::assertSame(TemplateClassFact::CHANNEL_NEW, $verdict->getPrimaryChannel());
	}

	public function testPerSiteSecondaryDifferingFromThePrimaryIsNeverAConflict(): void
	{
		$facts = $this->factsFor(PairingDualChannelControl::class);
		$verdict = $this->judgeFacts($facts);

		self::assertSame(PairingBaseTemplateReplica::class, $verdict->getPrimaryClass());
		self::assertSame(TemplateClassFact::CHANNEL_PHPDOC, $verdict->getPrimaryChannel());

		// The facts ladder downgrades its primary to MAYBE over the cross-channel disagreement;
		// the verdict derives certainty from the winning candidate itself, where the per-site
		// secondary is no ambiguity at all.
		$factsPrimary = $facts->getTemplateClass();
		self::assertNotNull($factsPrimary);
		self::assertSame(Certainty::MAYBE, $factsPrimary->getCertainty());
		self::assertSame(Certainty::HAPPENS, $verdict->getPrimaryCertainty());

		$sitePairings = $verdict->getSitePairings();
		self::assertCount(1, $sitePairings);
		self::assertSame(PairingUnrelatedTemplateReplica::class, $sitePairings[0]->getClassName());
		self::assertSame(TemplateClassFact::CHANNEL_CREATE_TEMPLATE, $sitePairings[0]->getChannel());
		self::assertSame(22, $sitePairings[0]->getLine());

		self::assertSame([], $verdict->getConflicts());
		self::assertSame([], $verdict->getOpaques());
	}

	public function testBareDefaultsClassResolvesToTheLadderFloorWithZeroFindings(): void
	{
		$verdict = $this->judgeFacts($this->factsFor(PairingDefaultsSilentPresenter::class));

		self::assertSame(UiTemplate::class, $verdict->getPrimaryClass());
		self::assertSame(TemplateClassFact::CHANNEL_TEMPLATE_FLOOR, $verdict->getPrimaryChannel());
		self::assertSame(Certainty::HAPPENS, $verdict->getPrimaryCertainty());
		self::assertSame([], $verdict->getCandidates());
		self::assertSame([], $verdict->getSitePairings());
		self::assertSame([], $verdict->getConflicts());
		self::assertSame([], $verdict->getOpaques());
	}

	public function testUnknownDeclarationCandidateNeverConflictsWithAKnownRuntime(): void
	{
		$declaration = new TemplateClassFact(
			self::VanishedTemplateClass,
			TemplateClassFact::CHANNEL_PHPDOC,
			Certainty::HAPPENS,
			[],
		);
		$runtime = new TemplateClassFact(
			PairingBaseTemplateReplica::class,
			TemplateClassFact::CHANNEL_NEW,
			Certainty::HAPPENS,
			[21],
		);
		$verdict = $this->judgeFacts(new PhpRenderFacts([], [], $declaration, [], [], [$declaration, $runtime]));

		self::assertSame([], $verdict->getConflicts());

		$opaques = $verdict->getOpaques();
		self::assertCount(1, $opaques);
		self::assertSame(TemplateClassFact::CHANNEL_PHPDOC, $opaques[0]->getChannel());
		self::assertSame(PairingOpaque::NO_SITE_LINE, $opaques[0]->getLine());

		self::assertSame(PairingBaseTemplateReplica::class, $verdict->getPrimaryClass());
		self::assertSame(TemplateClassFact::CHANNEL_NEW, $verdict->getPrimaryChannel());
	}

	public function testConventionDriftAgainstDeclarationsConflictsAndWinsThePrimary(): void
	{
		$facts = $this->factsFor(PairingConventionDriftControl::class);
		$verdict = $this->judgeFacts($facts);

		self::assertCount(3, $facts->getTemplateClassCandidates());

		$conflicts = $verdict->getConflicts();
		self::assertCount(2, $conflicts);

		self::assertSame(PairingConflict::KIND_NO_ANCESTRY, $conflicts[0]->getKind());
		self::assertSame(PairingChildTemplateReplica::class, $conflicts[0]->getDeclaredClass());
		self::assertSame(PairingUnrelatedTemplateReplica::class, $conflicts[0]->getRuntimeClass());
		self::assertSame(
			[TemplateClassFact::CHANNEL_PHPDOC, TemplateClassFact::CHANNEL_CONVENTION],
			$conflicts[0]->getChannels(),
		);
		self::assertSame([13], $conflicts[0]->getLines());

		self::assertSame(PairingConflict::KIND_NO_ANCESTRY, $conflicts[1]->getKind());
		self::assertSame(
			[TemplateClassFact::CHANNEL_GENERIC_BINDING, TemplateClassFact::CHANNEL_CONVENTION],
			$conflicts[1]->getChannels(),
		);

		self::assertSame(PairingUnrelatedTemplateReplica::class, $verdict->getPrimaryClass());
		self::assertSame(TemplateClassFact::CHANNEL_CONVENTION, $verdict->getPrimaryChannel());
		self::assertSame(Certainty::HAPPENS, $verdict->getPrimaryCertainty());
		self::assertSame([], $verdict->getSitePairings());
		self::assertSame([], $verdict->getOpaques());
	}

	public function testConventionAgreeingWithDeclarationsIsConflictFreeAndWinsThePrimary(): void
	{
		$verdict = $this->judgeFacts($this->factsFor(PairingConventionAgreePresenter::class));

		self::assertCount(3, $verdict->getCandidates());
		self::assertSame(PairingBaseTemplateReplica::class, $verdict->getPrimaryClass());
		self::assertSame(TemplateClassFact::CHANNEL_CONVENTION, $verdict->getPrimaryChannel());
		self::assertSame(Certainty::HAPPENS, $verdict->getPrimaryCertainty());
		self::assertSame([], $verdict->getConflicts());
		self::assertSame([], $verdict->getOpaques());
	}

	public function testConventionSubtypeOfDeclarationsIsCompatibleAndWinsThePrimary(): void
	{
		$verdict = $this->judgeFacts($this->factsFor(PairingConventionNarrowerControl::class));

		self::assertSame(PairingChildTemplateReplica::class, $verdict->getPrimaryClass());
		self::assertSame(TemplateClassFact::CHANNEL_CONVENTION, $verdict->getPrimaryChannel());
		self::assertSame(Certainty::HAPPENS, $verdict->getPrimaryCertainty());
		self::assertSame([], $verdict->getConflicts());
		self::assertSame([], $verdict->getOpaques());
	}

	public function testTwoDifferentConventionReturnsInOneClassConflictIntraChannel(): void
	{
		$verdict = $this->judgeFacts($this->factsFor(PairingConventionIntraChannelControl::class));

		$conflicts = $verdict->getConflicts();
		self::assertCount(1, $conflicts);
		self::assertSame(PairingConflict::KIND_INTRA_CHANNEL, $conflicts[0]->getKind());
		self::assertSame(PairingBaseTemplateReplica::class, $conflicts[0]->getDeclaredClass());
		self::assertSame(PairingUnrelatedTemplateReplica::class, $conflicts[0]->getRuntimeClass());
		self::assertSame(
			[TemplateClassFact::CHANNEL_CONVENTION, TemplateClassFact::CHANNEL_CONVENTION],
			$conflicts[0]->getChannels(),
		);
		self::assertSame([20, 23], $conflicts[0]->getLines());

		self::assertSame(PairingBaseTemplateReplica::class, $verdict->getPrimaryClass());
		self::assertSame(TemplateClassFact::CHANNEL_CONVENTION, $verdict->getPrimaryChannel());
		self::assertSame(Certainty::MAYBE, $verdict->getPrimaryCertainty());
		self::assertSame([], $verdict->getOpaques());
	}

	public function testDynamicConventionReturnIsOpaqueNotAConflict(): void
	{
		$verdict = $this->judgeFacts($this->factsFor(PairingConventionDynamicControl::class));

		self::assertSame([], $verdict->getConflicts());

		$opaques = $verdict->getOpaques();
		self::assertCount(1, $opaques);
		self::assertSame(TemplateClassFact::CHANNEL_CONVENTION, $opaques[0]->getChannel());
		self::assertSame(19, $opaques[0]->getLine());

		// M1 parity: the unresolvable marker CAN be the primary; getOpaques() is the signal
		// consumers must join before trusting it.
		self::assertSame(TemplateClassFact::DYNAMIC_CLASS_NAME, $verdict->getPrimaryClass());
		self::assertSame(TemplateClassFact::CHANNEL_CONVENTION, $verdict->getPrimaryChannel());
		self::assertSame(Certainty::HAPPENS, $verdict->getPrimaryCertainty());
	}

	// App parity: BaseControl's formatTemplateClass() delegates to getTemplateClass() - the
	// delegation return must not surface as a second (opaque) convention candidate.
	public function testDelegatingConventionHookYieldsOneCandidateAndNoOpaque(): void
	{
		$facts = $this->factsFor(PairingConventionDelegatingControl::class);
		$verdict = $this->judgeFacts($facts);

		$candidates = $facts->getTemplateClassCandidates();
		self::assertCount(1, $candidates);
		self::assertSame(PairingBaseTemplateReplica::class, $candidates[0]->getClassName());
		self::assertSame(TemplateClassFact::CHANNEL_CONVENTION, $candidates[0]->getChannel());
		self::assertSame(Certainty::HAPPENS, $candidates[0]->getCertainty());
		self::assertSame([21], $candidates[0]->getSites());

		self::assertSame(PairingBaseTemplateReplica::class, $verdict->getPrimaryClass());
		self::assertSame(TemplateClassFact::CHANNEL_CONVENTION, $verdict->getPrimaryChannel());
		self::assertSame([], $verdict->getConflicts());
		self::assertSame([], $verdict->getOpaques());
	}

	public function testOverrideReturnedFactoryCreationIsClassLevelAndParticipatesInConflicts(): void
	{
		$verdict = $this->judgeFacts($this->factsFor(PairingOverrideReturnFactoryPresenter::class));

		$conflicts = $verdict->getConflicts();
		self::assertCount(2, $conflicts);

		self::assertSame(PairingConflict::KIND_NARROWER_DECLARATION, $conflicts[0]->getKind());
		self::assertSame(PairingChildTemplateReplica::class, $conflicts[0]->getDeclaredClass());
		self::assertSame(PairingBaseTemplateReplica::class, $conflicts[0]->getRuntimeClass());
		self::assertSame(
			[TemplateClassFact::CHANNEL_PHPDOC, TemplateClassFact::CHANNEL_NEW],
			$conflicts[0]->getChannels(),
		);
		self::assertSame([22], $conflicts[0]->getLines());

		self::assertSame(PairingConflict::KIND_NARROWER_DECLARATION, $conflicts[1]->getKind());
		self::assertSame(
			[TemplateClassFact::CHANNEL_GENERIC_BINDING, TemplateClassFact::CHANNEL_NEW],
			$conflicts[1]->getChannels(),
		);

		self::assertSame(PairingBaseTemplateReplica::class, $verdict->getPrimaryClass());
		self::assertSame(TemplateClassFact::CHANNEL_NEW, $verdict->getPrimaryChannel());
		self::assertSame(Certainty::HAPPENS, $verdict->getPrimaryCertainty());
		self::assertSame([], $verdict->getSitePairings());
		self::assertSame([], $verdict->getOpaques());
	}

	public function testOverrideAssignedFactoryCreationIsClassLevelNotAPerSitePairing(): void
	{
		$verdict = $this->judgeFacts($this->factsFor(PairingOverrideAssignFactoryPresenter::class));

		self::assertSame([], $verdict->getSitePairings());

		$conflicts = $verdict->getConflicts();
		self::assertCount(2, $conflicts);
		self::assertSame(PairingConflict::KIND_NARROWER_DECLARATION, $conflicts[0]->getKind());
		self::assertSame(PairingChildTemplateReplica::class, $conflicts[0]->getDeclaredClass());
		self::assertSame(PairingBaseTemplateReplica::class, $conflicts[0]->getRuntimeClass());
		self::assertSame([23], $conflicts[0]->getLines());

		self::assertSame(PairingBaseTemplateReplica::class, $verdict->getPrimaryClass());
		self::assertSame(TemplateClassFact::CHANNEL_NEW, $verdict->getPrimaryChannel());
		self::assertSame(Certainty::HAPPENS, $verdict->getPrimaryCertainty());
	}

	// An overridden app-ancestor createTemplate() body is dead code for the entry class: its
	// return new must not seed a class-level NEW candidate, so no conflict is mintable from it.
	public function testOverriddenAncestorCreateTemplateBodySeedsNoNewCandidateAndNoConflict(): void
	{
		$facts = $this->factsFor(PairingDeadAncestorChildControl::class);
		$verdict = $this->judgeFacts($facts);

		$candidates = $facts->getTemplateClassCandidates();
		self::assertCount(2, $candidates);
		self::assertSame(TemplateClassFact::CHANNEL_GENERIC_BINDING, $candidates[0]->getChannel());
		self::assertSame(PairingBaseTemplateReplica::class, $candidates[0]->getClassName());
		self::assertSame(TemplateClassFact::CHANNEL_NEW, $candidates[1]->getChannel());
		self::assertSame(PairingBaseTemplateReplica::class, $candidates[1]->getClassName());

		self::assertSame(PairingBaseTemplateReplica::class, $verdict->getPrimaryClass());
		self::assertSame(TemplateClassFact::CHANNEL_NEW, $verdict->getPrimaryChannel());
		self::assertSame(Certainty::HAPPENS, $verdict->getPrimaryCertainty());
		self::assertSame([], $verdict->getSitePairings());
		self::assertSame([], $verdict->getConflicts());
		self::assertSame([], $verdict->getOpaques());
	}

	public function testJudgingTheSameFactsTwiceYieldsAnIdenticalVerdict(): void
	{
		$facts = $this->factsFor(PairingNarrowerDeclarationPresenter::class);
		$judge = new PairingJudge(self::createReflectionProvider());

		$first = $judge->judge($facts);
		$second = $judge->judge($facts);
		self::assertSame(serialize($first), serialize($second));

		$independent = (new PairingJudge(self::createReflectionProvider()))
			->judge($this->factsFor(PairingNarrowerDeclarationPresenter::class));
		self::assertSame(serialize($first), serialize($independent));
	}

	public function testPairingSourcesNeverTouchTheAnalysisCache(): void
	{
		$dir = realpath(__DIR__ . '/../../../../../src/Latte/Bridge/Pairing');
		self::assertNotFalse($dir);

		$files = glob($dir . '/*.php');
		self::assertNotFalse($files);
		self::assertCount(5, $files);

		foreach ($files as $file) {
			self::assertStringNotContainsString('LatteAnalysisCache', FileSystem::read($file));
		}
	}

	private function factsFor(string $className): PhpRenderFacts
	{
		$appRoot = realpath(__DIR__ . '/Fixtures/App');
		self::assertNotFalse($appRoot);

		/** @var Parser $parser */
		$parser = self::getContainer()->getService('currentPhpVersionRichParser');

		$walk = new PhpRenderWalk(
			self::createReflectionProvider(),
			$parser,
			[$appRoot],
			new TemplateFactoryDefaultResolver(null),
			new DiscoveryResolver(null, [], $appRoot),
		);

		return $walk->factsFor($className);
	}

	private function judgeFacts(PhpRenderFacts $facts): PairingVerdict
	{
		return (new PairingJudge(self::createReflectionProvider()))->judge($facts);
	}

}
