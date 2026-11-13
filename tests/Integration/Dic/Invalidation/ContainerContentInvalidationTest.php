<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Dic\Invalidation;

use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;
use function array_merge;

/**
 * The rows that pin CONTENT sensitivity: each changes what a container SAYS while leaving its path and
 * the file set as they were. They are also the two ends of the consumer range - a parameter retype
 * states its answer through the type extensions, a removed setup call through the dead-code channel
 * alone - and each re-analyses exactly the files whose tracked question got a different answer.
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
				'reanalysed' => ['Consumer.php'],
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
				'reanalysed' => ['SetupService.php'],
			],
		]);
	}

	// A service nothing in the corpus asks about: the container really is rebuilt with other bytes,
	// and only the class it now constructs - whose dead-code answer moved - is re-analysed.
	public function testServiceAddedToEveryContainer(): void
	{
		$this->assertScenario('content-service-added', [
			[
				'mutate' => function (InvalidationScenario $scenario): void {
					$this->writeConfig($scenario, 'alpha', $this->profileConfig('alpha') . "\textra: Unrelated\n");
					$this->writeConfig($scenario, 'beta', $this->profileConfig('beta') . "\textra: Unrelated\n");
				},
				'expect' => $this->seedErrors(),
				'maxReanalysed' => 1,
			],
		]);
	}

}
