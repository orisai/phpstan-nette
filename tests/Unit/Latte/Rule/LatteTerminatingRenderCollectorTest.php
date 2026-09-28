<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Rule;

use OriPhpstan\Nette\Latte\Rule\LatteTerminatingRenderCollector;
use PhpParser\Node;
use PHPStan\Analyser\Error;
use PHPStan\Collectors\Collector;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;
use Tests\OriPhpstan\Nette\Unit\Latte\Fixtures\Rule\NeverTerminatingRenderProbeControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Fixtures\Rule\TerminatingRenderProbeControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Rule\Fixtures\CollectedDataEchoRule;
use function array_map;
use function dirname;
use function realpath;

/**
 * @extends RuleTestCase<CollectedDataEchoRule>
 */
final class LatteTerminatingRenderCollectorTest extends RuleTestCase
{

	private const FIXTURE = __DIR__ . '/../Fixtures/Rule/TerminatingRenderProbeControl.php';

	private const NEVER_FIXTURE = __DIR__ . '/../Fixtures/Rule/NeverTerminatingRenderProbeControl.php';

	private bool $enabled = true;

	private bool $discoveryStoreEnabled = true;

	private ?string $appRootPath = null;

	// Only an ALWAYS-terminating render hook: a conditional abort still reaches the dispatch, and a
	// helper that always aborts is not a dispatch hook at all.
	public function testCollectsOnlyAlwaysTerminatingRenderHooks(): void
	{
		self::assertSame([TerminatingRenderProbeControl::class . '::renderAborted'], $this->collected());
	}

	// The same first-party boundary PhpRenderWalk uses: a class outside it can never be a renderer,
	// so its methods are collected data nobody could ask about.
	public function testCollectsNothingOutsideTheAppRoot(): void
	{
		$this->appRootPath = self::fixtureDirectory() . '/__elsewhere__';

		self::assertSame([], $this->collected());
	}

	public function testCollectsNothingWhenAnalysisIsOff(): void
	{
		$this->enabled = false;

		self::assertSame([], $this->collected());
	}

	public function testCollectsNothingWhenTheDiscoveryStoreIsOff(): void
	{
		$this->discoveryStoreEnabled = false;

		self::assertSame([], $this->collected());
	}

	// The second source of termination, inherited whole from PHPStan rather than restated here: a
	// hook calling a method DECLARED never returns no more than one calling a configured terminator.
	// Every spelling PHPStan resolves to an explicit never is pinned, because the vocabulary is the
	// thing being inherited - a spelling silently dropping out would silently reopen false positives.
	public function testCollectsRenderHooksTerminatedByADeclaredNeverReturn(): void
	{
		self::assertSame(
			[
				NeverTerminatingRenderProbeControl::class . '::renderCallsNever',
				NeverTerminatingRenderProbeControl::class . '::renderCallsNeverReturn',
				NeverTerminatingRenderProbeControl::class . '::renderCallsNeverReturns',
				NeverTerminatingRenderProbeControl::class . '::renderCallsNoReturn',
				NeverTerminatingRenderProbeControl::class . '::renderCallsPhpstanTagNever',
			],
			$this->collected(self::NEVER_FIXTURE),
		);
	}

	// DECLARED never only, and deliberately so: PHPStan gates its own never fall-through on
	// isExplicit(), and consuming inferred never would tie suppression to inference precision that
	// moves between releases - a silent-suppression channel. An always-throwing helper that declares
	// nothing is therefore no evidence, which is also what makes the pins above about the vocabulary
	// and not about the body shape they all share.
	public function testUndeclaredAlwaysThrowingHelperIsNoTerminator(): void
	{
		self::assertNotContains(
			NeverTerminatingRenderProbeControl::class . '::renderCallsPlain',
			$this->collected(self::NEVER_FIXTURE),
		);
	}

	/**
	 * @return list<string>
	 */
	private function collected(?string $fixture = null): array
	{
		return array_map(
			static fn (Error $error): string => $error->getMessage(),
			$this->gatherAnalyserErrors([$fixture ?? self::FIXTURE]),
		);
	}

	protected function getRule(): Rule
	{
		return new CollectedDataEchoRule(LatteTerminatingRenderCollector::class);
	}

	/**
	 * @return array<Collector<Node, mixed>>
	 */
	protected function getCollectors(): array
	{
		return [
			new LatteTerminatingRenderCollector(
				TestGuard::latte($this->enabled, false, $this->enabled && $this->discoveryStoreEnabled),
				[$this->appRootPath ?? self::fixtureDirectory()],
			),
		];
	}

	private static function fixtureDirectory(): string
	{
		$real = realpath(self::FIXTURE);
		self::assertNotFalse($real);

		return dirname($real);
	}

	/**
	 * @return list<string>
	 */
	public static function getAdditionalConfigFiles(): array
	{
		return [__DIR__ . '/phpstan-test.neon'];
	}

}
