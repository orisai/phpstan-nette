<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Dic\Invalidation;

use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;
use function array_merge;

/**
 * The rows that pin the salt's CONTENT sensitivity. Every other committed row moves the container FILE
 * SET or the loader's profile keys, so a digest reduced to `profile:path` - one that never hashes a
 * byte of container - passes all of them. These two change what a container SAYS while leaving its
 * path, its class name and the file set exactly as they were, which is the only shape that can tell a
 * content hash from a path list.
 *
 * They are also the two ends of the consumer range: a parameter retype states its answer through the
 * type extensions, a removed setup call through the dead-code channel alone.
 */
final class ContainerContentInvalidationTest extends DicInvalidationMatrixCase
{

	private const SetupProbeDead = 'SetupService.php:6 :: shipmonk.deadMethod :: Unused SetupService::configure';

	public function testParameterRetypedInEveryContainer(): void
	{
		$this->assertScenario('content-parameter-retyped', [
			[
				'mutate' => function (InvalidationScenario $scenario): void {
					$this->writeConfig($scenario, 'alpha', $this->profileConfig('alpha', '7'));
					$this->writeConfig($scenario, 'beta', $this->profileConfig('beta', '7'));
				},
				'expect' => $this->seedErrors(
					'alpha, beta',
					'array{sharedParam: int, profileParam: string}',
				),
			],
		]);
	}

	public function testSetupCallRemovedFromEveryContainer(): void
	{
		$this->assertScenario('content-setup-removed', [
			[
				'mutate' => function (InvalidationScenario $scenario): void {
					$this->writeConfig($scenario, 'alpha', $this->profileConfig('alpha', null, false));
					$this->writeConfig($scenario, 'beta', $this->profileConfig('beta', null, false));
				},
				'expect' => array_merge($this->seedErrors(), [self::SetupProbeDead]),
			],
		]);
	}

}
