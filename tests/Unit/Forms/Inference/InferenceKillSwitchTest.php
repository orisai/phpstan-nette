<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference;

use PHPStan\Testing\TypeInferenceTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InstalledVersionsGuard;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;
use function is_string;

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
			if ($args[0] === 'type' && is_string($args[2])) {
				$args[2] = self::vendorAnswer($args[2]);
			}

			$this->assertFileAsserts(...$args);
		}
	}

	// The fixture spells phpstan-nette's answer. From nette/forms 3.2.9 phpstan-nette 2.0.13+ defers to the
	// declared conditional return type, which nette/forms 3.3 no longer maps `true` to an array in.
	private static function vendorAnswer(string $expected): string
	{
		if (!InstalledVersionsGuard::satisfies('nette/forms', '>=3.2.9')) {
			return $expected;
		}

		if ($expected === 'array<string, mixed>') {
			return InstalledVersionsGuard::satisfies('nette/forms', '^3.3')
				? 'Nette\Utils\ArrayHash<mixed>'
				: 'array<mixed>';
		}

		return $expected === 'Nette\Utils\ArrayHash' ? 'Nette\Utils\ArrayHash<mixed>' : $expected;
	}

	public function testParameterIsFalse(): void
	{
		self::assertFalse(self::getContainer()->getParameter('orisai')['nette']['forms']['enabled']);
	}

}
