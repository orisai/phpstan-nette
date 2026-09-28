<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Rule;

use OriPhpstan\Nette\Latte\Rule\LatteConventionNameCollector;
use PhpParser\Node;
use PHPStan\Analyser\Error;
use PHPStan\Collectors\Collector;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;
use Tests\OriPhpstan\Nette\Unit\Latte\Fixtures\Rule\ConventionNameWidget;
use Tests\OriPhpstan\Nette\Unit\Latte\Rule\Fixtures\CollectedDataEchoRule;
use function array_map;

/**
 * @extends RuleTestCase<CollectedDataEchoRule>
 */
final class LatteConventionNameCollectorTest extends RuleTestCase
{

	private const FIXTURE = __DIR__ . '/../Fixtures/Rule/ConventionNameCaller.php';

	private bool $enabled = true;

	private bool $discoveryStoreEnabled = true;

	// The receiver TYPE is what says which class's convention directory the name lands in, and only
	// a Scope can answer that. Every other setFile shape in the fixture is deliberately not a
	// bare-name write: a full path, an empty name, a non-constant argument.
	public function testCollectsTheReceiverClassAndTheBareNameOnly(): void
	{
		self::assertSame([ConventionNameWidget::class . '|uploadAgreement'], $this->collected());
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

	/**
	 * @return list<string>
	 */
	private function collected(): array
	{
		return array_map(
			static fn (Error $error): string => $error->getMessage(),
			$this->gatherAnalyserErrors([self::FIXTURE]),
		);
	}

	protected function getRule(): Rule
	{
		return new CollectedDataEchoRule(LatteConventionNameCollector::class);
	}

	/**
	 * @return array<Collector<Node, mixed>>
	 */
	protected function getCollectors(): array
	{
		return [new LatteConventionNameCollector(
			TestGuard::latte($this->enabled, false, $this->enabled && $this->discoveryStoreEnabled),
		)];
	}

	/**
	 * @return list<string>
	 */
	public static function getAdditionalConfigFiles(): array
	{
		return [__DIR__ . '/phpstan-test.neon'];
	}

}
