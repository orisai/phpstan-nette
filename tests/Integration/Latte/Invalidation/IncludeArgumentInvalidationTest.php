<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Invalidation;

use Nette\Utils\FileSystem;
use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;
use function dirname;
use function getmypid;
use function implode;
use function sort;
use function uniqid;

// Matrix group G - include ARGUMENTS read further down than the template they are passed to. An
// argument travels through every include hop below its includer, and the includer's own contract
// check reads the narrowing captures it wrote itself, so each template depends on the content of
// every transitive includer and on its own slice - none of which its exported edge fingerprint or
// its component's topology digest reflects.
final class IncludeArgumentInvalidationTest extends LatteInvalidationMatrixCase
{

	public function testAnArgumentEditReachesATemplateTwoIncludesDown(): void
	{
		$this->assertScenario(
			'args-two-hops',
			[
				[
					'mutate' => static fn (InvalidationScenario $scenario) => $scenario->write(
						'inc/a.latte',
						"{include 'b.latte', x => 'str'}\n",
					),
					'expect' => $this->withOrphans(
						['a', 'b', 'c'],
						['inc/c.latte:1 :: binaryOp.invalid :: Binary operation "+" between string and 1 results in an error.'],
					),
				],
				[
					'mutate' => static fn (InvalidationScenario $scenario) => $scenario->write(
						'inc/a.latte',
						"{include 'b.latte', y => 'str'}\n",
					),
					'expect' => $this->withOrphans(
						['a', 'b', 'c'],
						['inc/c.latte:1 :: variable.undefined :: Undefined variable: $x'],
					),
				],
			],
			static function (InvalidationScenario $scenario): void {
				$scenario->write('inc/a.latte', "{include 'b.latte', x => 1}\n");
				$scenario->write('inc/b.latte', "{include 'c.latte'}\n");
				$scenario->write('inc/c.latte', "{\$x + 1}\n");
			},
			$this->withOrphans(['a', 'b', 'c']),
		);
	}

	// The writer stores a's captures after the run that analysed a; the run after that must see them.
	public function testTheIncluderReadsItsOwnNarrowingSlice(): void
	{
		$storePath = dirname(__DIR__, 4) . '/var/tmp/latte-inval-own-slice-' . getmypid() . '-' . uniqid('', true);
		$this->setParameter('orisai.nette.latte.narrowing.storePath', $storePath);
		$scenario = $this->newScenario('own-slice');

		try {
			$this->seed($scenario);
			$scenario->write('inc/a.latte', "{include 'b.latte', x => strtoupper('a')}\n");
			$scenario->write('inc/b.latte', "{varType int \$x}\n{\$x}\n");

			$mismatch = 'inc/a.latte:1 :: orisai.nette.latte.includeTypeMismatch :: Variable $x provided as \'A\' '
				. 'does not match declared type int in \'' . $scenario->projectRelative('inc/b.latte') . '\'.';

			$this->assertSettled($scenario, $this->withOrphans(['a', 'b'], [$mismatch]), 'seed');

			$scenario->write('inc/a.latte', "{include 'b.latte', x => strlen('a')}\n");
			$this->assertSettled($scenario, $this->withOrphans(['a', 'b']), 'step 0');

			$scenario->write('inc/a.latte', "{include 'b.latte', x => strtoupper('a')}\n");
			$this->assertSettled($scenario, $this->withOrphans(['a', 'b'], [$mismatch]), 'step 1');
		} finally {
			$scenario->cleanup();
			FileSystem::delete($storePath);
		}
	}

	/**
	 * @param list<string> $expected
	 */
	private function assertSettled(InvalidationScenario $scenario, array $expected, string $step): void
	{
		$warm = $scenario->settle();
		$cold = $scenario->cold();

		self::assertSame($cold->getErrorText(), $warm->getErrorText(), "$step: cold == warm");
		self::assertSame(
			implode("\n", $expected),
			$warm->getErrorText(),
			"$step: the settled state must be the stated one",
		);
	}

	/**
	 * @param list<string> $templates
	 * @param list<string> $errors
	 * @return list<string>
	 */
	private function withOrphans(array $templates, array $errors = []): array
	{
		foreach ($templates as $template) {
			$errors[] = 'inc/' . $template . '.latte:1 :: orisai.nette.latte.orphanTemplate :: '
				. 'No analysable render, include or layout path reaches this template file.';
		}

		sort($errors);

		return $errors;
	}

}
