<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Unit;

use PHPStan\Testing\PHPStanTestCase;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;

final class KillSwitchConfigTest extends PHPStanTestCase
{

	use VersionGroupGate;

	/** @return list<string> */
	public static function getAdditionalConfigFiles(): array
	{
		return [__DIR__ . '/../phpstan-test.neon'];
	}

	public function testParameterDeclared(): void
	{
		self::assertTrue(self::getContainer()->getParameter('orisai')['nette']['forms']['enabled']);
	}

}
