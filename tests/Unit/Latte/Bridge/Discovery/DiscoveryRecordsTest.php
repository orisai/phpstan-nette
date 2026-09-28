<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Discovery;

use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Latte\Bridge\Discovery\CandidatePath;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryFact;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryRecords;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderFacts;
use OriPhpstan\Nette\Latte\Bridge\ViewFact;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;

final class DiscoveryRecordsTest extends BaseTestCase
{

	public function testNullDiscoveryYieldsNoRecords(): void
	{
		self::assertSame([], DiscoveryRecords::forClass('App\\Foo', PhpRenderFacts::empty()));
	}

	public function testChosenExistingViewCandidateBecomesARecordWithTheViewFactCertainty(): void
	{
		$facts = self::facts(
			['detail' => [new CandidatePath('app/detail.latte', true, true, CandidatePath::KIND_SET_FILE)]],
			[],
			['detail' => new ViewFact('detail', Certainty::HAPPENS, [], [])],
		);

		self::assertSame(
			[
				'app/detail.latte' => [
					['class' => 'App\\Foo', 'view' => 'detail', 'kind' => 'setFile', 'certainty' => 'happens'],
				],
			],
			DiscoveryRecords::forClass('App\\Foo', $facts),
		);
	}

	public function testChosenButMissingCandidateStaysFactsOnly(): void
	{
		$facts = self::facts(
			['detail' => [new CandidatePath('app/detail.latte', false, true, CandidatePath::KIND_CONVENTION)]],
			[],
			['detail' => new ViewFact('detail', Certainty::HAPPENS, [], [])],
		);

		self::assertSame([], DiscoveryRecords::forClass('App\\Foo', $facts));
	}

	public function testExistingButNotChosenCandidateStaysFactsOnly(): void
	{
		$facts = self::facts(
			['detail' => [new CandidatePath('app/detail.latte', true, false, CandidatePath::KIND_FORMULA)]],
			[],
			['detail' => new ViewFact('detail', Certainty::HAPPENS, [], [])],
		);

		self::assertSame([], DiscoveryRecords::forClass('App\\Foo', $facts));
	}

	public function testEmptyStringViewBucketMapsToNullViewWithUnknownCertainty(): void
	{
		$facts = self::facts(
			['' => [new CandidatePath('app/control.latte', true, true, CandidatePath::KIND_SET_FILE)]],
			[],
			[],
		);

		self::assertSame(
			[
				'app/control.latte' => [
					['class' => 'App\\Foo', 'view' => null, 'kind' => 'setFile', 'certainty' => 'unknown'],
				],
			],
			DiscoveryRecords::forClass('App\\Foo', $facts),
		);
	}

	public function testViewWithoutAViewFactDegradesToUnknownCertainty(): void
	{
		$facts = self::facts(
			['detail' => [new CandidatePath('app/detail.latte', true, true, CandidatePath::KIND_FORMULA)]],
			[],
			[],
		);

		self::assertSame(
			[
				'app/detail.latte' => [
					['class' => 'App\\Foo', 'view' => 'detail', 'kind' => 'formula', 'certainty' => 'unknown'],
				],
			],
			DiscoveryRecords::forClass('App\\Foo', $facts),
		);
	}

	public function testChosenExistingLayoutCandidateBecomesANullViewLayoutRecord(): void
	{
		$facts = self::facts(
			['detail' => [new CandidatePath('app/detail.latte', true, true, CandidatePath::KIND_FORMULA)]],
			[
				new CandidatePath('app/@layout.latte', true, true, CandidatePath::KIND_LAYOUT),
				new CandidatePath('app/templates/@layout.latte', false, false, CandidatePath::KIND_LAYOUT),
			],
			['detail' => new ViewFact('detail', Certainty::HAPPENS, [], [])],
		);

		self::assertSame(
			[
				'app/detail.latte' => [
					['class' => 'App\\Foo', 'view' => 'detail', 'kind' => 'formula', 'certainty' => 'happens'],
				],
				'app/@layout.latte' => [
					['class' => 'App\\Foo', 'view' => null, 'kind' => 'layout', 'certainty' => 'unknown'],
				],
			],
			DiscoveryRecords::forClass('App\\Foo', $facts),
		);
	}

	// A layout is only ever loaded from inside a view template's own compiled prepare()
	// (UIRuntime::initialize), so a class that provably renders no view can never reach one.
	public function testViewlessClosedClassEmitsNoLayoutRecord(): void
	{
		$facts = self::facts(
			[],
			[new CandidatePath('app/@layout.latte', true, true, CandidatePath::KIND_LAYOUT)],
			[],
		);

		self::assertSame([], DiscoveryRecords::forClass('App\\Foo', $facts));
	}

	public function testClassRenderingAViewKeepsItsLayoutRecord(): void
	{
		$facts = self::facts(
			['detail' => [new CandidatePath('app/detail.latte', true, true, CandidatePath::KIND_FORMULA)]],
			[new CandidatePath('app/@layout.latte', true, true, CandidatePath::KIND_LAYOUT)],
			['detail' => new ViewFact('detail', Certainty::HAPPENS, [], [])],
		);

		self::assertSame(
			[
				'app/detail.latte' => [
					['class' => 'App\\Foo', 'view' => 'detail', 'kind' => 'formula', 'certainty' => 'happens'],
				],
				'app/@layout.latte' => [
					['class' => 'App\\Foo', 'view' => null, 'kind' => 'layout', 'certainty' => 'unknown'],
				],
			],
			DiscoveryRecords::forClass('App\\Foo', $facts),
		);
	}

	// An unreadable discovery is not a proof of viewlessness - the link stays exactly as today.
	public function testOpaqueDiscoveryKeepsTheLayoutRecord(): void
	{
		$facts = self::facts(
			[],
			[new CandidatePath('app/@layout.latte', true, true, CandidatePath::KIND_LAYOUT)],
			[],
			[['reason' => 'presenter name unresolved', 'line' => null]],
		);

		self::assertSame(
			[
				'app/@layout.latte' => [
					['class' => 'App\\Foo', 'view' => null, 'kind' => 'layout', 'certainty' => 'unknown'],
				],
			],
			DiscoveryRecords::forClass('App\\Foo', $facts),
		);
	}

	// The corpus case conditions 2 and 3 exist for: a dynamic setFile leaves the view set OPEN,
	// which is unresolvable rather than empty (PdfPresenter, Error4xxPresenter).
	public function testOpenViewSetKeepsTheLayoutRecord(): void
	{
		$facts = self::facts(
			[],
			[new CandidatePath('app/@layout.latte', true, true, CandidatePath::KIND_LAYOUT)],
			[],
			[],
			true,
		);

		self::assertSame(
			[
				'app/@layout.latte' => [
					['class' => 'App\\Foo', 'view' => null, 'kind' => 'layout', 'certainty' => 'unknown'],
				],
			],
			DiscoveryRecords::forClass('App\\Foo', $facts),
		);
	}

	/**
	 * @return array<string, array{CandidatePath}>
	 */
	public static function unrenderedViewCandidateProvider(): array
	{
		return [
			'missing file' => [new CandidatePath('app/detail.latte', false, true, CandidatePath::KIND_FORMULA)],
			'not chosen' => [new CandidatePath('app/detail.latte', true, false, CandidatePath::KIND_FORMULA)],
		];
	}

	/**
	 * @dataProvider unrenderedViewCandidateProvider
	 */
	public function testViewCandidateThatNeverRendersDoesNotUnlockTheLayout(CandidatePath $candidate): void
	{
		$facts = self::facts(
			['detail' => [$candidate]],
			[new CandidatePath('app/@layout.latte', true, true, CandidatePath::KIND_LAYOUT)],
			['detail' => new ViewFact('detail', Certainty::HAPPENS, [], [])],
		);

		self::assertSame([], DiscoveryRecords::forClass('App\\Foo', $facts));
	}

	public function testCandidateEscapingTheProjectRootStaysFactsOnly(): void
	{
		$facts = self::facts(
			['detail' => [new CandidatePath('/outside/detail.latte', true, true, CandidatePath::KIND_FORMULA)]],
			[],
			['detail' => new ViewFact('detail', Certainty::HAPPENS, [], [])],
		);

		self::assertSame(
			[],
			DiscoveryRecords::forClass('App\\Foo', $facts),
			'an absolute candidate path escaped the project root - no analysable template can carry its record',
		);
	}

	// rendersAView() has to read the CANDIDATES rather than the appended records: the escaped-root
	// view candidate above carries no record of its own, yet it still proves the class renders, which
	// has to unlock an in-root LAYOUT candidate's record. A fixture with no layout candidate (like the
	// one above) cannot discriminate that from a rendersAView() that reads the records instead.
	public function testViewCandidateEscapingRootStillUnlocksTheLayout(): void
	{
		$facts = self::facts(
			['detail' => [new CandidatePath('/outside/detail.latte', true, true, CandidatePath::KIND_FORMULA)]],
			[new CandidatePath('app/@layout.latte', true, true, CandidatePath::KIND_LAYOUT)],
			['detail' => new ViewFact('detail', Certainty::HAPPENS, [], [])],
		);

		self::assertSame(
			[
				'app/@layout.latte' => [
					['class' => 'App\\Foo', 'view' => null, 'kind' => 'layout', 'certainty' => 'unknown'],
				],
			],
			DiscoveryRecords::forClass('App\\Foo', $facts),
			'a chosen+existing view candidate outside the project root still proves the class renders, so its in-root layout candidate must still emit a record',
		);
	}

	public function testOneTemplateChosenUnderTwoViewsYieldsTwoRecords(): void
	{
		$shared = 'app/shared.latte';
		$facts = self::facts(
			[
				'detail' => [new CandidatePath($shared, true, true, CandidatePath::KIND_SET_FILE)],
				'index' => [new CandidatePath($shared, true, true, CandidatePath::KIND_SET_FILE)],
			],
			[],
			[
				'detail' => new ViewFact('detail', Certainty::HAPPENS, [], []),
				'index' => new ViewFact('index', Certainty::MAYBE, [], []),
			],
		);

		self::assertSame(
			[
				$shared => [
					['class' => 'App\\Foo', 'view' => 'detail', 'kind' => 'setFile', 'certainty' => 'happens'],
					['class' => 'App\\Foo', 'view' => 'index', 'kind' => 'setFile', 'certainty' => 'maybe'],
				],
			],
			DiscoveryRecords::forClass('App\\Foo', $facts),
		);
	}

	public function testIdenticalRecordsAreDeduplicated(): void
	{
		// Two identical chosen+existing candidates on one view (e.g. a setFile seed and its
		// duplicate) must collapse into one record.
		$facts = self::facts(
			[
				'detail' => [
					new CandidatePath('app/detail.latte', true, true, CandidatePath::KIND_SET_FILE),
					new CandidatePath('app/detail.latte', true, true, CandidatePath::KIND_SET_FILE),
				],
			],
			[],
			['detail' => new ViewFact('detail', Certainty::HAPPENS, [], [])],
		);

		self::assertSame(
			[
				'app/detail.latte' => [
					['class' => 'App\\Foo', 'view' => 'detail', 'kind' => 'setFile', 'certainty' => 'happens'],
				],
			],
			DiscoveryRecords::forClass('App\\Foo', $facts),
		);
	}

	/**
	 * @param array<string, list<CandidatePath>> $viewCandidates
	 * @param list<CandidatePath> $layoutCandidates
	 * @param array<string, ViewFact> $views
	 * @param list<array{reason: string, line: int|null}> $opaques
	 */
	private static function facts(
		array $viewCandidates,
		array $layoutCandidates,
		array $views,
		array $opaques = [],
		bool $hasOpenViewSet = false
	): PhpRenderFacts
	{
		return new PhpRenderFacts(
			[],
			[],
			null,
			[],
			['/project/app/Foo.php'],
			[],
			$views,
			[],
			$hasOpenViewSet,
			new DiscoveryFact($viewCandidates, $layoutCandidates, $opaques, []),
		);
	}

}
