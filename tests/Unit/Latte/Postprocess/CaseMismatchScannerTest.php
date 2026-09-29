<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Postprocess;

use Latte\Parser;
use OriPhpstan\Nette\Latte\Compile\Diagnostic;
use OriPhpstan\Nette\Latte\Customs\HarvestedCustoms;
use OriPhpstan\Nette\Latte\Postprocess\CaseMismatchScanner;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;

/**
 * @group latte2
 */
final class CaseMismatchScannerTest extends BaseTestCase
{

	public function testWrongCaseFilterIsReportedWithBothSpellings(): void
	{
		$diagnostics = $this->scan("{\$x|Upper}\n");

		self::assertCount(1, $diagnostics);
		self::assertSame('orisaiNette.latte.filterCaseMismatch', $diagnostics[0]->getIdentifier());
		self::assertStringContainsString("'Upper'", $diagnostics[0]->getMessage());
		self::assertStringContainsString("'upper'", $diagnostics[0]->getMessage());
		self::assertStringContainsString('Latte 3', $diagnostics[0]->getMessage());
		self::assertSame(1, $diagnostics[0]->getLatteLine());
	}

	public function testExactCaseFilterIsQuiet(): void
	{
		self::assertSame([], $this->scan("{\$x|upper}\n"));
	}

	public function testWrongCaseFunctionIsReportedWithBothSpellings(): void
	{
		$diagnostics = $this->scan("{Clamp(\$x, 1, 2)}\n");

		self::assertCount(1, $diagnostics);
		self::assertSame('orisaiNette.latte.functionCaseMismatch', $diagnostics[0]->getIdentifier());
		self::assertStringContainsString("'Clamp'", $diagnostics[0]->getMessage());
		self::assertStringContainsString("'clamp'", $diagnostics[0]->getMessage());
	}

	public function testExactCaseFunctionIsQuiet(): void
	{
		self::assertSame([], $this->scan("{clamp(\$x, 1, 2)}\n"));
	}

	public function testUnregisteredNameIsQuietRegardlessOfCase(): void
	{
		self::assertSame([], $this->scan("{\$x|DefinitelyNotARealFilter}\n{DefinitelyNotARealFunction()}\n"));
	}

	public function testLogicalOrOperatorIsNeverMistakenForAFilterPipe(): void
	{
		// The '||' adjacency must not be mistaken for a single filter '|' - only the genuine
		// function-call mismatch inside the condition should be reported.
		$diagnostics = $this->scan("{if \$a || Clamp(\$x)}x{/if}\n");

		self::assertCount(1, $diagnostics);
		self::assertSame('orisaiNette.latte.functionCaseMismatch', $diagnostics[0]->getIdentifier());
	}

	public function testStringLiteralContentIsNeverScannedAsACall(): void
	{
		self::assertSame([], $this->scan("{=\"a|Upper|b Clamp(\"}\n"));
	}

	public function testMethodCallAndStaticCallAreNeverMistakenForAFunctionCall(): void
	{
		// clamp/Clamp appear only as the tail of ->clamp(/::clamp(, never as a bare call.
		self::assertSame([], $this->scan("{\$obj->Clamp(\$x)}\n{Foo::Clamp(\$x)}\n"));
	}

	public function testAmbiguousBuiltInSpellingPairStaysQuietForBothSpellings(): void
	{
		// dataStream/datastream are both independently, deliberately registered by Latte's own
		// Defaults - neither spelling is "the" canonical one, so this must never fire either way.
		self::assertSame([], $this->scan("{\$x|dataStream}\n{\$x|datastream}\n"));
	}

	public function testHarvestedFilterOriginalNameIsRespected(): void
	{
		$harvested = new HarvestedCustoms([], [], [], [], ['myfilter' => 'myFilter'], []);

		$diagnostics = $this->scan("{\$x|MyFilter}\n", $harvested);

		self::assertCount(1, $diagnostics);
		self::assertSame('orisaiNette.latte.filterCaseMismatch', $diagnostics[0]->getIdentifier());
		self::assertStringContainsString("'MyFilter'", $diagnostics[0]->getMessage());
		self::assertStringContainsString("'myFilter'", $diagnostics[0]->getMessage());
	}

	public function testHarvestedFunctionOriginalNameIsRespected(): void
	{
		$harvested = new HarvestedCustoms([], [], [], [], [], ['myfunction' => 'myFunction']);

		$diagnostics = $this->scan("{MyFunction(\$x)}\n", $harvested);

		self::assertCount(1, $diagnostics);
		self::assertSame('orisaiNette.latte.functionCaseMismatch', $diagnostics[0]->getIdentifier());
	}

	public function testHarvestedExactCaseIsQuiet(): void
	{
		$harvested = new HarvestedCustoms([], [], [], [], ['myfilter' => 'myFilter'], ['myfunction' => 'myFunction']);

		self::assertSame([], $this->scan("{\$x|myFilter}\n{myFunction(\$x)}\n", $harvested));
	}

	/**
	 * @return list<Diagnostic>
	 */
	private function scan(string $latteSource, ?HarvestedCustoms $harvested = null): array
	{
		$tokens = (new Parser())->parse($latteSource);

		return (new CaseMismatchScanner($harvested))->scan($tokens);
	}

}
