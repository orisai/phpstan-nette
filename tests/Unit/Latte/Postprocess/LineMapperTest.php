<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Postprocess;

use OriPhpstan\Nette\Latte\Postprocess\LineMapper;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;

final class LineMapperTest extends BaseTestCase
{

	public function testBuildsForwardFilledMap(): void
	{
		$php = "<?php\nclass X {\npublic function main(): array\n{\nif (\$a) /* line 4 */ {\necho \$b /* line 5 */;\n}\nreturn [];\n}\n}\n";
		$map = (new LineMapper())->buildMap($php);

		self::assertSame(4, $map[5]);
		self::assertSame(5, $map[6]);
		self::assertSame(5, $map[7]); // forward-fill after last marker
	}

	public function testFirstMarkerOnLineWins(): void
	{
		$php = "<?php\necho \$a /* line 2 */ . \$b /* line 3 */;\n";
		$map = (new LineMapper())->buildMap($php);

		self::assertSame(2, $map[2]);
	}

	public function testUnmarkedStatementBeforeAMarkerTakesThatMarker(): void
	{
		$php = "<?php\nforeach (\$a as \$b) /* line 2 */ {\necho '<label';\n\$x = f();\necho \$x->y() /* line 3 */;\n}\n";
		$map = (new LineMapper())->buildMap($php);

		self::assertSame(2, $map[3]);
		self::assertSame(3, $map[4]);
		self::assertSame(3, $map[5]);
		self::assertSame(3, $map[6]);
	}

	public function testClosingBraceKeepsThePreviousMarker(): void
	{
		$php = "<?php\nif (\$a) /* line 4 */ {\necho \$b /* line 5 */;\n}\necho \$c /* line 9 */;\n";
		$map = (new LineMapper())->buildMap($php);

		self::assertSame(5, $map[4]);
		self::assertSame(9, $map[5]);
	}

	public function testNextMarkerBelowThePreviousIsNotTakenBackwards(): void
	{
		$php = "<?php\necho \$a /* line 9 */;\n\$tmp = 1;\necho \$b /* line 2 */;\n";
		$map = (new LineMapper())->buildMap($php);

		self::assertSame(9, $map[3]);
	}

}
