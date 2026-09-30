<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference;

use PHPStan\Testing\TypeInferenceTestCase;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;

final class InferenceKillSwitchTest extends TypeInferenceTestCase
{

	use VersionGroupGate;

	/** @return list<string> */
	public static function getAdditionalConfigFiles(): array
	{
		return [__DIR__ . '/disabled.neon'];
	}

	/** @return list<string> */
	public static function getAdditionalAnalysedFiles(): array
	{
		return [__DIR__ . '/../Support/markers.php'];
	}

	public function testKillSwitchDisablesInferenceCarriers(): void
	{
		$file = __DIR__ . '/Fixtures/Type/KillSwitch.php';
		foreach ($this->gatherAssertTypes($file) as $args) {
			$this->assertFileAsserts(...$args);
		}
	}

	public function testParameterIsFalse(): void
	{
		self::assertFalse(self::getContainer()->getParameter('orisai')['nette']['forms']['enabled']);
	}

}
