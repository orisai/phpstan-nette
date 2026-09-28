<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge;

use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Latte\Bridge\AssignmentFact;
use OriPhpstan\Nette\Latte\Bridge\Discovery\CandidatePath;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryFact;
use OriPhpstan\Nette\Latte\Bridge\MutationFact;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderFacts;
use OriPhpstan\Nette\Latte\Bridge\RenderSiteFact;
use OriPhpstan\Nette\Latte\Bridge\SetFileFact;
use OriPhpstan\Nette\Latte\Bridge\TemplateClassFact;
use OriPhpstan\Nette\Latte\Bridge\ViewFact;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function array_keys;

final class PhpRenderFactsTest extends BaseTestCase
{

	public function testCanonicalHashIsByteIdenticalAcrossConstructionOrders(): void
	{
		$assignmentA = new AssignmentFact('string', Certainty::HAPPENS, [['file' => '/a.php', 'line' => 5]]);
		$assignmentB = new AssignmentFact('int', Certainty::MAYBE, [['file' => '/b.php', 'line' => 9]]);

		$setFileOne = new SetFileFact(
			SetFileFact::KIND_LITERAL,
			'/templates/one.latte',
			Certainty::HAPPENS,
			['file' => '/a.php', 'line' => 10],
			MutationFact::PHASE_ACTION,
			MutationFact::EFFECTIVE_YES,
		);
		$setFileTwo = new SetFileFact(
			SetFileFact::KIND_OPAQUE,
			null,
			Certainty::MAYBE,
			['file' => '/b.php', 'line' => 20],
			MutationFact::PHASE_OUTSIDE,
			MutationFact::EFFECTIVE_YES,
		);

		$renderOne = new RenderSiteFact(true, '/templates/a.latte', ['file' => '/a.php', 'line' => 30]);
		$renderTwo = new RenderSiteFact(false, null, ['file' => '/b.php', 'line' => 40]);

		$templateClass = new TemplateClassFact('App\\FooTemplate', TemplateClassFact::CHANNEL_NEW, Certainty::HAPPENS);

		$mutationAction = new MutationFact(
			MutationFact::KIND_SET_VIEW,
			MutationFact::PHASE_ACTION,
			MutationFact::EFFECTIVE_YES,
			60,
			'beta',
		);
		$mutationStartup = new MutationFact(
			MutationFact::KIND_SET_FILE,
			MutationFact::PHASE_STARTUP,
			MutationFact::EFFECTIVE_YES,
			65,
			null,
		);
		$viewAlpha = new ViewFact('alpha', Certainty::HAPPENS, [['file' => '/a.php', 'line' => 50]], ['actionAlpha']);
		$viewBeta = new ViewFact('beta', Certainty::MAYBE, [['file' => '/a.php', 'line' => 60]], [$mutationAction]);

		$first = new PhpRenderFacts(
			['b' => $assignmentB, 'a' => $assignmentA],
			[$setFileTwo, $setFileOne],
			$templateClass,
			[$renderTwo, $renderOne],
			['/b.php', '/a.php'],
			[],
			['beta' => $viewBeta, 'alpha' => $viewAlpha],
			[$mutationAction, $mutationStartup],
			true,
		);

		$second = new PhpRenderFacts(
			['a' => $assignmentA, 'b' => $assignmentB],
			[$setFileOne, $setFileTwo],
			$templateClass,
			[$renderOne, $renderTwo],
			['/a.php', '/b.php'],
			[],
			['alpha' => $viewAlpha, 'beta' => $viewBeta],
			[$mutationStartup, $mutationAction],
			true,
		);

		self::assertSame($first->getCanonicalHash(), $second->getCanonicalHash());
		self::assertSame(['a', 'b'], array_keys($first->getAssignments()));
		self::assertSame(['/a.php', '/b.php'], $first->getReadSet());
		self::assertSame(['alpha', 'beta'], array_keys($first->getViews()));
		// Lifecycle order, not construction order: the startup mutation sorts ahead of the action one.
		self::assertSame([$mutationStartup, $mutationAction], $first->getMutations());
		self::assertTrue($first->hasOpenViewSet());
	}

	public function testCanonicalHashDedupesReadSet(): void
	{
		$facts = new PhpRenderFacts([], [], null, [], ['/a.php', '/a.php', '/b.php']);

		self::assertSame(['/a.php', '/b.php'], $facts->getReadSet());
	}

	public function testEmptyReturnsEmptyFacts(): void
	{
		$facts = PhpRenderFacts::empty();

		self::assertSame([], $facts->getAssignments());
		self::assertSame([], $facts->getSetFileTargets());
		self::assertNull($facts->getTemplateClass());
		self::assertSame([], $facts->getTemplateClassCandidates());
		self::assertSame([], $facts->getRenderSites());
		self::assertSame([], $facts->getReadSet());
		self::assertSame([], $facts->getViews());
		self::assertSame([], $facts->getMutations());
		self::assertFalse($facts->hasOpenViewSet());
		self::assertNull($facts->getDiscovery());
	}

	public function testCanonicalHashChangesWhenDiscoveryAppears(): void
	{
		$discovery = new DiscoveryFact(
			['default' => [new CandidatePath('App/templates/default.latte', true, true, CandidatePath::KIND_FORMULA)]],
			[],
			[],
			[['path' => '/abs/App/templates/default.latte', 'kind' => DiscoveryFact::PROBE_FILE, 'result' => true]],
		);

		$without = new PhpRenderFacts([], [], null, [], ['/a.php']);
		$with = new PhpRenderFacts([], [], null, [], ['/a.php'], [], [], [], false, $discovery);

		self::assertNotSame($without->getCanonicalHash(), $with->getCanonicalHash());
	}

	public function testDiscoveryRoundTripsThroughToArray(): void
	{
		$discovery = new DiscoveryFact(
			[
				'detail' => [
					new CandidatePath('App/custom.latte', false, true, CandidatePath::KIND_SET_FILE),
					new CandidatePath('App/templates/Foo/detail.latte', true, false, CandidatePath::KIND_FORMULA),
				],
				'' => [new CandidatePath('App/foo.latte', false, true, CandidatePath::KIND_CONVENTION)],
			],
			[new CandidatePath('App/templates/@layout.latte', true, true, CandidatePath::KIND_LAYOUT)],
			[['reason' => 'unassigned override', 'line' => 12], ['reason' => 'unmapped presenter', 'line' => null]],
			[
				['path' => '/abs/App/templates', 'kind' => DiscoveryFact::PROBE_DIR, 'result' => true],
				['path' => '/abs/App/custom.latte', 'kind' => DiscoveryFact::PROBE_FILE, 'result' => false],
			],
		);
		$facts = new PhpRenderFacts([], [], null, [], ['/a.php'], [], [], [], false, $discovery);

		$roundTripped = PhpRenderFacts::fromArray($facts->toArray());

		self::assertSame($facts->getCanonicalHash(), $roundTripped->getCanonicalHash());
		self::assertEquals($facts, $roundTripped);

		$discoveryBack = $roundTripped->getDiscovery();
		self::assertNotNull($discoveryBack);
		// Canonical: view keys and existence-set entries are sorted, candidate order preserved.
		self::assertSame(['', 'detail'], array_keys($discoveryBack->getViewCandidates()));
		self::assertSame(
			[
				['path' => '/abs/App/custom.latte', 'kind' => DiscoveryFact::PROBE_FILE, 'result' => false],
				['path' => '/abs/App/templates', 'kind' => DiscoveryFact::PROBE_DIR, 'result' => true],
			],
			$discoveryBack->getExistenceSet(),
		);
		$candidate = $discoveryBack->getViewCandidates()['detail'][0];
		self::assertSame('App/custom.latte', $candidate->getPath());
		self::assertFalse($candidate->exists());
		self::assertTrue($candidate->isChosen());
		self::assertSame(CandidatePath::KIND_SET_FILE, $candidate->getKind());
		self::assertCount(1, $discoveryBack->getLayoutCandidates());
		self::assertSame(
			[['reason' => 'unassigned override', 'line' => 12], ['reason' => 'unmapped presenter', 'line' => null]],
			$discoveryBack->getOpaques(),
		);
	}

	public function testCanonicalHashChangesWhenASecondTemplateClassCandidateAppears(): void
	{
		$primary = new TemplateClassFact(
			'App\\FooTemplate',
			TemplateClassFact::CHANNEL_CREATE_TEMPLATE,
			Certainty::HAPPENS,
		);
		$candidateOne = new TemplateClassFact(
			'App\\FooTemplate',
			TemplateClassFact::CHANNEL_CREATE_TEMPLATE,
			Certainty::HAPPENS,
			[10],
		);
		$candidateTwo = new TemplateClassFact(
			'App\\BarTemplate',
			TemplateClassFact::CHANNEL_FACTORY_STATIC,
			Certainty::HAPPENS,
			[20],
		);

		$one = new PhpRenderFacts([], [], $primary, [], ['/a.php'], [$candidateOne]);
		$two = new PhpRenderFacts([], [], $primary, [], ['/a.php'], [$candidateOne, $candidateTwo]);

		self::assertNotSame($one->getCanonicalHash(), $two->getCanonicalHash());
	}

	public function testFromArrayRoundTripsToArray(): void
	{
		$mutation = new MutationFact(
			MutationFact::KIND_SET_ACTION,
			MutationFact::PHASE_SIGNAL,
			MutationFact::EFFECTIVE_MAYBE,
			7,
			'switched',
		);
		$facts = new PhpRenderFacts(
			['tpl' => new AssignmentFact('string', Certainty::UNKNOWN, [['file' => '/a.php', 'line' => 1]])],
			[new SetFileFact(
				SetFileFact::KIND_CONVENTION,
				'/a/path.latte',
				Certainty::NEVER,
				['file' => '/a.php', 'line' => 2],
				MutationFact::PHASE_BEFORE_RENDER,
				MutationFact::EFFECTIVE_YES,
			)],
			new TemplateClassFact('App\\FooTemplate', TemplateClassFact::CHANNEL_PHPDOC, Certainty::MAYBE),
			[new RenderSiteFact(true, '/a/path.latte', ['file' => '/a.php', 'line' => 3])],
			['/a.php'],
			[new TemplateClassFact(
				'App\\BarTemplate',
				TemplateClassFact::CHANNEL_CREATE_TEMPLATE,
				Certainty::HAPPENS,
				[4, 5],
			)],
			['detail' => new ViewFact(
				'detail',
				Certainty::HAPPENS,
				[['file' => '/a.php', 'line' => 6]],
				['renderDetail', $mutation],
			)],
			[$mutation],
			true,
		);

		$roundTripped = PhpRenderFacts::fromArray($facts->toArray());

		self::assertSame($facts->getCanonicalHash(), $roundTripped->getCanonicalHash());
		self::assertEquals($facts, $roundTripped);

		$assignment = $roundTripped->getAssignments()['tpl'];
		self::assertSame('string', $assignment->getTypeString());
		self::assertSame([['file' => '/a.php', 'line' => 1]], $assignment->getSites());

		$setFile = $roundTripped->getSetFileTargets()[0];
		self::assertSame(SetFileFact::KIND_CONVENTION, $setFile->getKind());
		self::assertSame('/a/path.latte', $setFile->getPath());
		self::assertSame(MutationFact::PHASE_BEFORE_RENDER, $setFile->getPhase());
		self::assertSame(MutationFact::EFFECTIVE_YES, $setFile->getEffectiveness());

		self::assertTrue($roundTripped->hasOpenViewSet());
		$view = $roundTripped->getViews()['detail'];
		self::assertSame('detail', $view->getName());
		self::assertSame(Certainty::HAPPENS, $view->getCertainty());
		self::assertSame([['file' => '/a.php', 'line' => 6]], $view->getSites());
		$sources = $view->getSources();
		self::assertCount(2, $sources);
		self::assertSame('renderDetail', $sources[0]);
		self::assertInstanceOf(MutationFact::class, $sources[1]);
		self::assertSame(MutationFact::KIND_SET_ACTION, $sources[1]->getKind());
		self::assertSame(MutationFact::PHASE_SIGNAL, $sources[1]->getPhase());
		self::assertSame(MutationFact::EFFECTIVE_MAYBE, $sources[1]->getEffectiveness());
		self::assertSame(7, $sources[1]->getLine());
		self::assertSame('switched', $sources[1]->getArgument());
		self::assertCount(1, $roundTripped->getMutations());

		$templateClass = $roundTripped->getTemplateClass();
		self::assertNotNull($templateClass);
		self::assertSame('App\\FooTemplate', $templateClass->getClassName());
		self::assertSame(TemplateClassFact::CHANNEL_PHPDOC, $templateClass->getChannel());

		$renderSite = $roundTripped->getRenderSites()[0];
		self::assertTrue($renderSite->hasFileArg());
		self::assertSame('/a/path.latte', $renderSite->getLiteralPath());
		self::assertSame(['file' => '/a.php', 'line' => 3], $renderSite->getSite());

		$candidates = $roundTripped->getTemplateClassCandidates();
		self::assertCount(1, $candidates);
		self::assertSame('App\\BarTemplate', $candidates[0]->getClassName());
		self::assertSame(TemplateClassFact::CHANNEL_CREATE_TEMPLATE, $candidates[0]->getChannel());
		self::assertSame(Certainty::HAPPENS, $candidates[0]->getCertainty());
		self::assertSame([4, 5], $candidates[0]->getSites());
	}

	/**
	 * @return iterable<string, array{0: Certainty::*}>
	 */
	public function provideCertaintyStates(): iterable
	{
		yield 'happens' => [Certainty::HAPPENS];
		yield 'maybe' => [Certainty::MAYBE];
		yield 'never' => [Certainty::NEVER];
		yield 'unknown' => [Certainty::UNKNOWN];
	}

	/**
	 * @param Certainty::* $certainty
	 *
	 * @dataProvider provideCertaintyStates
	 */
	public function testCertaintyStatesConstructibleThroughFormsClassAndRoundTripCanonicalization(
		string $certainty
	): void
	{
		$assignment = new AssignmentFact('mixed', $certainty, []);
		$setFile = new SetFileFact(
			SetFileFact::KIND_OPAQUE,
			null,
			$certainty,
			['file' => '/a.php', 'line' => 1],
			MutationFact::PHASE_RENDER,
			MutationFact::EFFECTIVE_YES,
		);
		$templateClass = new TemplateClassFact(
			'App\\FooTemplate',
			TemplateClassFact::CHANNEL_GENERIC_BINDING,
			$certainty,
		);

		self::assertSame($certainty, $assignment->getCertainty());
		self::assertSame($certainty, $setFile->getCertainty());
		self::assertSame($certainty, $templateClass->getCertainty());

		self::assertSame($certainty, AssignmentFact::fromArray($assignment->toArray())->getCertainty());
		self::assertSame($certainty, SetFileFact::fromArray($setFile->toArray())->getCertainty());
		self::assertSame($certainty, TemplateClassFact::fromArray($templateClass->toArray())->getCertainty());
	}

}
