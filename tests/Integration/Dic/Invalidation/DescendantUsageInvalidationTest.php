<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Dic\Invalidation;

use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;
use function array_merge;

/**
 * DicUsageProvider is the one DIC consumer whose answer is not a pure function of the container files:
 * the container writes `new HelperHolder(new Helper)`, but the constructor that call reaches is
 * declared by HelperBase, a file the container never names. Helper is constructed WITHOUT being a
 * service, so it is absent from $wiring and its `extends` clause moves no container byte - the salt
 * provably cannot see the edit, and the hop has to be resolved where the result cache re-derives it on
 * every run rather than in a per-file verdict cached against the wrong file's inputs.
 *
 * The rows below are the two ways that used to break, both against a byte-identical container: a
 * hierarchy edit that leaves the salt unmoved, and an answer read out of runtime autoload state, which
 * differs between the main process and a worker.
 */
final class DescendantUsageInvalidationTest extends DicInvalidationMatrixCase
{

	private const AncestorConstructorDead = 'HelperBase.php:6 :: shipmonk.deadMethod :: Unused HelperBase::__construct';

	/**
	 * maxReanalysed is 1 on purpose: only Helper.php changed, HelperBase.php is a dependency of nothing
	 * and is NOT reanalysed, and its verdict still has to follow. A fix that bought this by discarding
	 * the cache - or by salting the hierarchy - fails here while every cold == warm assertion passes.
	 */
	public function testDescendantHierarchyEditWithoutAnyContainerFileChanging(): void
	{
		$this->assertScenario('descendant-hierarchy', [
			[
				'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
					'Helper.php',
					$this->detachedHelper(),
				),
				'expect' => array_merge($this->seedErrors(), [self::AncestorConstructorDead]),
				'maxReanalysed' => 1,
			],
			[
				'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
					'Helper.php',
					$this->helper(),
				),
				'expect' => $this->seedErrors(),
				'maxReanalysed' => 1,
			],
		]);
	}

	/**
	 * The determinism half. Registering the corpus autoloader is what decides whether a runtime
	 * class_exists()/is_a() lookup can answer at all, and PHPStan's workers do not inherit the classes
	 * the container compilation happened to load - so an answer that differs between these two runs is
	 * an answer that differs between the main process and a worker on one and the same tree.
	 */
	public function testDescendantVerdictDoesNotDependOnAutoloadState(): void
	{
		$stated = self::errorText($this->seedErrors());

		self::assertSame($stated, $this->settledErrorText('descendant-autoloaded', true));
		self::assertSame($stated, $this->settledErrorText('descendant-not-autoloaded', false));
	}

}
