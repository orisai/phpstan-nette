<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Dic\Invalidation;

use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;

/**
 * The rows that reproduce the hole this matrix was written for: the container-file digest is the only
 * thing salting the whole result cache, and a profile can be renamed, added or reordered WITHOUT any
 * container file changing - two profiles may compile to one file, and the loader's array keys are the
 * profile identities the rules print. Before the salt carried the profile keys, both rows below left
 * `Result cache restored. 0 files will be reanalysed.` and every DIC message in the project naming
 * profiles that no longer existed.
 *
 * These are deliberately NOT bounded by maxReanalysed: a changed profile identity SHOULD discard the
 * cache, since it moves the answer of every consumer in the analysed set.
 */
final class ProfileIdentityInvalidationTest extends DicInvalidationMatrixCase
{

	public function testProfileRenamedWithoutAnyContainerFileChanging(): void
	{
		$this->assertScenario('profile-renamed', [
			[
				'mutate' => fn (InvalidationScenario $scenario) => $this->writeLoader(
					$scenario,
					"\t'alpha' => 'alpha',\n\t'delta' => 'beta',",
				),
				'expect' => $this->seedErrors('alpha, delta'),
			],
		]);
	}

	public function testProfileAddedOntoAnAlreadyHashedContainerFile(): void
	{
		$this->assertScenario('profile-duplicated', [
			[
				'mutate' => fn (InvalidationScenario $scenario) => $this->writeLoader(
					$scenario,
					"\t'alpha' => 'alpha',\n\t'beta' => 'beta',\n\t'epsilon' => 'beta',",
				),
				'expect' => $this->seedErrors('alpha, beta, epsilon'),
			],
		]);
	}

	public function testProfileRemoved(): void
	{
		$this->assertScenario('profile-removed', [
			[
				'mutate' => fn (InvalidationScenario $scenario) => $this->writeLoader(
					$scenario,
					"\t'alpha' => 'alpha',",
				),
				'expect' => $this->seedErrors('alpha'),
			],
		]);
	}

}
