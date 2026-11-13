<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Dic\Invalidation;

use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;

/**
 * The half of the matrix that stops freshness from being bought with a blanket invalidation. Every
 * row asserts, BEFORE any error-set comparison, that the result cache was genuinely RESTORED and that
 * the edit stayed granular; a dependency on the neon SOURCES or on the container files' bytes
 * satisfies every cold == warm assertion in the other classes and fails here.
 *
 * The neon-comment row is the sharpest of them: the config really did change and Nette really did
 * rebuild the container (autoRebuild is forced on for static analysis), but no answer moved.
 */
final class InvalidationControlsTest extends DicInvalidationMatrixCase
{

	public function testServiceClassBodyEdit(): void
	{
		$this->assertScenario('control-body', [
			[
				'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
					'FooService.php',
					$this->fooService("\t\treturn 42;"),
				),
				'expect' => $this->seedErrors(),
				'maxReanalysed' => 1,
			],
		]);
	}

	public function testCommentOnlyEditInAServiceClass(): void
	{
		$this->assertScenario('control-comment', [
			[
				'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
					'FooService.php',
					$this->fooService("\t\t// the value nothing in this corpus reads\n\t\treturn 1;"),
				),
				'expect' => $this->seedErrors(),
				'maxReanalysed' => 1,
			],
		]);
	}

	public function testUnrelatedFileBodyEdit(): void
	{
		$this->assertScenario('control-unrelated', [
			[
				'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
					'Unrelated.php',
					$this->unrelated('7'),
				),
				'expect' => $this->seedErrors(),
				'maxReanalysed' => 1,
			],
		]);
	}

	// nette/di 3.2 suffixes every rebuilt container class with random bytes, so there the comment
	// does change the container file - and still no question any file asked gets another answer.
	public function testCommentOnlyEditInANeonConfig(): void
	{
		$this->assertScenario('control-neon-comment', [
			[
				'mutate' => function (InvalidationScenario $scenario): void {
					$this->writeConfig(
						$scenario,
						'alpha',
						"# a comment the compiled container cannot carry\n" . $this->readConfig($scenario, 'alpha'),
					);
				},
				'expect' => $this->seedErrors(),
				'reanalysed' => [],
			],
		]);
	}

}
