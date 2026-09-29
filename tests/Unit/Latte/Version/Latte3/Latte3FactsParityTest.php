<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Version\Latte3;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Version\Latte3\Latte3Adapter;
use OriPhpstan\Nette\Latte\Version\Latte3\Latte3Compiler;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\FactsFixtures;
use Tests\OriPhpstan\Nette\Toolkit\FactsJson;
use Tests\OriPhpstan\Nette\Toolkit\InstalledVersionsGuard;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;

// Fact parity with Latte 2: for every fixture valid on both majors, the Declarations, TemplateFacts
// and FormSite lists the Latte 3 adapter extracts from the node tree equal the Latte 2 token-scanner
// output committed by Latte2FactsFixtureTest.
/**
 * @group latte3
 */
final class Latte3FactsParityTest extends BaseTestCase
{

	// Syntax Latte 3 rejects outright; the adapter reports Latte's own parse error instead of facts.
	private const LATTE2_ONLY = [
		'fixtures/ifcurrent.latte' => 'nette/application 3.3 dropped {ifCurrent}',
		'latteforms/input-error.latte' => 'Latte 3 {inputError} requires an argument (Latte 2 echoed the last {input} error)',
		'latteforms/Rule/types.latte' => 'Latte 3 {label} must be closed ({/label} or /}); Latte 2 auto-closed it',
	];

	/**
	 * @dataProvider provideFixtures
	 */
	public function testFactsEqualTheLatte2Reference(string $lattePath, string $relativePath, string $jsonPath): void
	{
		$source = FileSystem::read($lattePath);

		if (isset(self::LATTE2_ONLY[$relativePath]) && !$this->isValidHere($relativePath)) {
			$parsed = (new Latte3Compiler())->parse($source);
			$failure = $parsed->getFailure();
			self::assertNotNull($failure, self::LATTE2_ONLY[$relativePath]);
			self::assertSame('orisaiNette.latte.parseError', $failure->getIdentifier());

			return;
		}

		self::assertFileExists($jsonPath, 'Latte2FactsFixtureTest generates the reference on the default profile');
		self::assertStringEqualsFile(
			$jsonPath,
			FactsJson::encode($this->adapter()->extractFacts($source, $relativePath)),
		);
	}

	// Review Focus 2, fact level: the header declaration reaches the same Declarations surface as on
	// Latte 2 - DeclarationInjector then injects it and PHPStan's own class.notFound reports the class,
	// which the spawn-level counterpart pins once the pipeline runs on Latte 3 (Task 11).
	public function testUnknownVarTypeClassIsReportedLikeLatte2(): void
	{
		$declarations = $this->adapter()
			->extractFacts("{varType UnknownClass \$x}\n{\$x}\n", 'version/unknown-vartype-class.latte')
			->getDeclarations();

		self::assertSame(['x' => 'UnknownClass'], $declarations->getHeaderVarTypes());
		self::assertSame(['x' => 1], $declarations->getHeaderVarTypeLines());
		self::assertSame([], $declarations->getMidFileVarTypes());
		self::assertSame([], $declarations->getVarTypePlacements());
	}

	/**
	 * @return iterable<string, array{string, string, string}>
	 */
	public function provideFixtures(): iterable
	{
		yield from FactsFixtures::all();
	}

	private function isValidHere(string $key): bool
	{
		return $key === 'fixtures/ifcurrent.latte'
			&& InstalledVersionsGuard::satisfies('nette/application', '<3.3');
	}

	private function adapter(): Latte3Adapter
	{
		return new Latte3Adapter(new Latte3Compiler(), TestAdapter::factory()->family());
	}

}
