<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component;

use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\IsolatedPhpstanConfig;
use function dirname;
use function substr_count;
use const PHP_BINARY;

/**
 * Never-No is load-bearing for a string offset on any of the three shape-carrying types
 * (FormShapeType, FormReplicatorType's own children, FormValuesObjectShapeType): core's own
 * NonexistentOffsetInArrayDimFetchRule reports independently on a definite No, so a No here would
 * double-report against FormShapeUnknownAccessRule's own message for the same access, replacing our
 * domain message with a generic one on top of it. Each case runs the REAL analyse (both rules active,
 * exactly as in production) against a genuinely absent name on a closed shape, and asserts our own
 * message is the ONLY one reported for that access - proving the type answers Maybe (silent to core),
 * never No. Covers all three types individually - a regression scoped to just one of them (as
 * FormValuesObjectShapeType's own explicit createNo() once was) would not be caught by testing only
 * FormShapeType.
 */
final class NeverNoRegressionTest extends BaseTestCase
{

	public function testUnknownNameOnClosedShapeIsReportedOnlyOnce(): void
	{
		$this->assertOnlyOurMessage(
			'UnknownNameOnClosedShape.php',
			"Form component 'nope' does not exist.",
		);
	}

	public function testUnknownKeyOnClosedValuesShapeIsReportedOnlyOnce(): void
	{
		$this->assertOnlyOurMessage(
			'UnknownKeyOnClosedValuesShape.php',
			"Form value 'nope' does not exist.",
		);
	}

	public function testUnknownNameOnClosedReplicatorOwnShapeIsReportedOnlyOnce(): void
	{
		$this->assertOnlyOurMessage(
			'UnknownNameOnClosedReplicatorOwnShape.php',
			"Form component 'nope' does not exist.",
		);
	}

	/**
	 * The same guarantee on the OTHER channel, and against the other cascade. ContainerModel answers
	 * every consumer file and every .latte, and it used to hand back an IComponent for a name a closed
	 * shape proves absent - which core then reported a second time as an undefined method, property or
	 * offset on top of the rule's own message. It answers the projector's ErrorType now, so each of
	 * the three member accesses below carries our message and nothing else.
	 */
	public function testUnknownNameOnClosedShapeThroughWalkIsReportedOnlyOnce(): void
	{
		$this->assertOnlyOurMessage(
			'UnknownNameOnClosedShapeThroughWalk.php',
			"Form component 'nope' does not exist.",
			[
				'Call to an undefined method',
				'Access to an undefined property',
				'Cannot access offset',
			],
			3,
		);
	}

	/**
	 * The same guarantee at the FORM level, where there was no guarantee to keep: a form-level access
	 * handed back the form's bare class, so the rule had no shape to read off the accessed node and
	 * the absence went unreported entirely while core reported each member access on the IComponent.
	 * The form carries its shape now, so the three accesses below carry our message and nothing else.
	 */
	public function testUnknownNameOnFormLevelShapeIsReportedOnlyOnce(): void
	{
		$this->assertOnlyOurMessage(
			'UnknownNameOnFormLevelShape.php',
			"Form component 'nope' does not exist.",
			[
				'Call to an undefined method',
				'Access to an undefined property',
				'Cannot access offset',
			],
			3,
		);
	}

	/**
	 * @param list<string> $alsoForbidden
	 */
	private function assertOnlyOurMessage(
		string $fixtureBasename,
		string $ourMessage,
		array $alsoForbidden = [],
		?int $expectedCount = null
	): void
	{
		$root = dirname(__DIR__, 4);
		$fixture = __DIR__ . '/Fixtures/RealAnalyse/' . $fixtureBasename;
		$isolated = IsolatedPhpstanConfig::create(__DIR__ . '/component-real.neon');

		try {
			$process = new Process(
				[
					PHP_BINARY,
					$root . '/vendor/bin/phpstan',
					'analyse',
					$fixture,
					'-c',
					$isolated->getConfigPath(),
					'--error-format=raw',
					'--no-progress',
					'--memory-limit=2048M',
				],
				$root,
			);
			$process->setTimeout(120.0);
			$process->run();
			$out = $process->getOutput() . $process->getErrorOutput();

			self::assertStringContainsString($ourMessage, $out);
			self::assertStringNotContainsString('does not exist on', $out);

			foreach ($alsoForbidden as $forbidden) {
				self::assertStringNotContainsString($forbidden, $out);
			}

			if ($expectedCount !== null) {
				self::assertSame($expectedCount, substr_count($out, $ourMessage));
			}
		} finally {
			$isolated->cleanup();
		}
	}

}
