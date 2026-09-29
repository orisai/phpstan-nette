<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Postprocess;

use OriPhpstan\Nette\Latte\Postprocess\LineMapper;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;

final class LineMapperTest extends BaseTestCase
{

	public function testBuildsForwardFilledMap(): void
	{
		$php = "<?php\nclass X {\npublic function main(): array\n{\nif (\$a) /* line 4 */ {\necho \$b /* line 5 */;\n}\nreturn [];\n}\n}\n";
		$map = (new LineMapper(ShapeFamily::LINE_MARKER_PATTERN_LINE))->buildMap($php);

		self::assertSame(4, $map[5]);
		self::assertSame(5, $map[6]);
		self::assertSame(5, $map[7]); // forward-fill after last marker
	}

	public function testFirstMarkerOnLineWins(): void
	{
		$php = "<?php\necho \$a /* line 2 */ . \$b /* line 3 */;\n";
		$map = (new LineMapper(ShapeFamily::LINE_MARKER_PATTERN_LINE))->buildMap($php);

		self::assertSame(2, $map[2]);
	}

	public function testUnmarkedStatementBeforeAMarkerTakesThatMarker(): void
	{
		$php = "<?php\nforeach (\$a as \$b) /* line 2 */ {\necho '<label';\n\$x = f();\necho \$x->y() /* line 3 */;\n}\n";
		$map = (new LineMapper(ShapeFamily::LINE_MARKER_PATTERN_LINE))->buildMap($php);

		self::assertSame(2, $map[3]);
		self::assertSame(3, $map[4]);
		self::assertSame(3, $map[5]);
		self::assertSame(3, $map[6]);
	}

	public function testClosingBraceKeepsThePreviousMarker(): void
	{
		$php = "<?php\nif (\$a) /* line 4 */ {\necho \$b /* line 5 */;\n}\necho \$c /* line 9 */;\n";
		$map = (new LineMapper(ShapeFamily::LINE_MARKER_PATTERN_LINE))->buildMap($php);

		self::assertSame(5, $map[4]);
		self::assertSame(9, $map[5]);
	}

	public function testNextMarkerBelowThePreviousIsNotTakenBackwards(): void
	{
		$php = "<?php\necho \$a /* line 9 */;\n\$tmp = 1;\necho \$b /* line 2 */;\n";
		$map = (new LineMapper(ShapeFamily::LINE_MARKER_PATTERN_LINE))->buildMap($php);

		self::assertSame(9, $map[3]);
	}

	// Latte 3.1 declarations.latte.php raw snapshot, main(): the marker carries a column.
	public function testPosMarkerMapsItsLineAndIgnoresTheColumn(): void
	{
		$php = "<?php\nclass X {\npublic function main(array \$ʟ_args): void\n{\nextract(\$ʟ_args);\nunset(\$ʟ_args);\n\n"
			. "if (\$this->global->snippetDriver?->renderSnippets(\$this->blocks[self::LayerSnippet], \$this->params)) {\nreturn;\n}\n\n"
			. "echo LR\\HtmlHelpers::escapeText(\$label) /* pos 9:1 */;\necho ' ';\necho LR\\HtmlHelpers::escapeText(\$x) /* pos 9:10 */;\n"
			. "\$flag ??= array_key_exists('flag', get_defined_vars()) ? null : false /* pos 10:1 */;\nif (\$flag) /* pos 11:1 */ {\necho 'on';\n}\n}\n}\n";
		$map = (new LineMapper(ShapeFamily::LINE_MARKER_PATTERN_POS))->buildMap($php);

		self::assertSame(9, $map[3]); // method declaration: prologue takes the first marker
		self::assertSame(9, $map[5]);
		self::assertSame(9, $map[6]);
		self::assertSame(9, $map[8]);
		self::assertSame(9, $map[12]);
		self::assertSame(9, $map[13]); // unmarked echo keeps the previous line
		self::assertSame(9, $map[14]);
		self::assertSame(10, $map[15]);
		self::assertSame(11, $map[16]);
		self::assertSame(11, $map[17]);
	}

	// Latte 3.0 declarations.latte.php raw snapshot, prepare(): same `/* line N */` markers as Latte 2.
	public function testLatte30LineMarkersInPrepare(): void
	{
		$php = "<?php\nclass X {\npublic function prepare(): array\n{\nextract(\$this->params);\n\n"
			. "\$x = 1 /* line 4 */;\n\$y = 2 /* line 5 */;\n\$z ??= array_key_exists('z', get_defined_vars()) ? null : 3 /* line 6 */;\nreturn get_defined_vars();\n}\n}\n";
		$map = (new LineMapper(ShapeFamily::LINE_MARKER_PATTERN_LINE))->buildMap($php);

		self::assertSame(4, $map[5]);
		self::assertSame(4, $map[7]);
		self::assertSame(5, $map[8]);
		self::assertSame(6, $map[9]);
		self::assertSame(6, $map[10]);
	}

	public function testLatte30PatternIgnoresPosMarkersAndViceVersa(): void
	{
		$php = "<?php\necho \$a /* line 4 */;\necho \$b /* pos 7:2 */;\n";

		$byLine = (new LineMapper(ShapeFamily::LINE_MARKER_PATTERN_LINE))->buildMap($php);
		self::assertSame(4, $byLine[2]);
		self::assertSame(4, $byLine[3]);

		$byPos = (new LineMapper(ShapeFamily::LINE_MARKER_PATTERN_POS))->buildMap($php);
		self::assertSame(1, $byPos[2]);
		self::assertSame(7, $byPos[3]);
	}

	/**
	 * @dataProvider provideBlockDocPatterns
	 */
	public function testBlockDocCommentStartsTheBlockMethodAtItsTagLine(string $pattern, string $marker): void
	{
		// parameters-blocks.latte.php raw snapshots: main() ends on line 2's marker, so a block
		// written on line 7 must not inherit it, and a body without any marker of its own sits on
		// the tag's line.
		$php = "<?php\nclass X {\npublic function main(array \$ʟ_args): void\n{\n\$this->renderBlock('b', get_defined_vars()) $marker;\necho \"x\";\n}\n\n\n"
			. "/** {block b} on line 7 */\npublic function blockB(array \$ʟ_args): void\n{\nextract(\$this->params);\nextract(\$ʟ_args);\nunset(\$ʟ_args);\n\necho 'static';\n}\n"
			. "/** n:snippet on line 9 */\npublic function blockS(array \$ʟ_args): void\n{\necho 'x';\n}\n}\n";
		$map = (new LineMapper($pattern))->buildMap($php);

		self::assertSame(2, $map[5]);
		self::assertSame(7, $map[10]);
		self::assertSame(7, $map[11]);
		self::assertSame(7, $map[13]);
		self::assertSame(7, $map[17]);
		self::assertSame(9, $map[19]);
		self::assertSame(9, $map[20]);
		self::assertSame(9, $map[22]);
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public function provideBlockDocPatterns(): iterable
	{
		yield 'latte 2' => [ShapeFamily::LINE_MARKER_PATTERN_LINE, '/* line 2 */'];
		yield 'latte 3.0' => [ShapeFamily::LINE_MARKER_PATTERN_LINE, '/* line 2 */'];
		yield 'latte 3.1' => [ShapeFamily::LINE_MARKER_PATTERN_POS, '/* pos 2:1 */'];
	}

	public function testEchoedTextMentioningALineIsNotAMarker(): void
	{
		$php = "<?php\necho \$a /* line 4 */;\necho '/** {block b} on line 9 */';\necho \$b;\n";
		$map = (new LineMapper(ShapeFamily::LINE_MARKER_PATTERN_LINE))->buildMap($php);

		self::assertSame(4, $map[3]);
		self::assertSame(4, $map[4]);
	}

}
