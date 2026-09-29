<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Compile\Diagnostic;
use OriPhpstan\Nette\Latte\Declarations\DeclarationScanner;
use OriPhpstan\Nette\Latte\Includes\DeclarationConsistencyChecker;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Includes\TemplateEdgeIndex;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Testing\PHPStanTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;
use function array_filter;
use function array_map;
use function array_values;
use function getmypid;
use function sys_get_temp_dir;
use function uniqid;

/**
 * @group latte2
 */
final class DeclarationConsistencyCheckerTest extends PHPStanTestCase
{

	use VersionGroupGate;

	// === FILE level: {templateType}-derived property type (native) vs top-level {varType} (override) ===

	public function testFileExactMatchReportsDuplicateDeclaration(): void
	{
		$dir = $this->isolatedDir('latte-consistency-file-exact');
		FileSystem::write(
			$dir . '/page.latte',
			"{templateType Tests\\OriPhpstan\\Nette\\Unit\\Latte\\Fixtures\\Support\\DeclarationConsistencyFixtureTemplate}\n"
			. "{varType string \$exact}\n",
		);

		try {
			$diagnostics = $this->diagnosticsFor($dir, 'page.latte');
			$duplicate = $this->only($diagnostics, 'orisaiNette.latte.duplicateDeclaration');

			self::assertCount(1, $duplicate);
			self::assertSame(2, $duplicate[0]->getLatteLine());
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testFileNarrowerOverrideReportsNarrowingOverride(): void
	{
		$dir = $this->isolatedDir('latte-consistency-file-narrow');
		FileSystem::write(
			$dir . '/page.latte',
			"{templateType Tests\\OriPhpstan\\Nette\\Unit\\Latte\\Fixtures\\Support\\DeclarationConsistencyFixtureTemplate}\n"
			. "{varType Exception \$narrow}\n",
		);

		try {
			self::assertContains('orisaiNette.latte.narrowingOverride', $this->idsFor($dir, 'page.latte'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testFileNarrowerOverrideNotReportedWhenConfigAllowsIt(): void
	{
		$dir = $this->isolatedDir('latte-consistency-file-narrow-allowed');
		FileSystem::write(
			$dir . '/page.latte',
			"{templateType Tests\\OriPhpstan\\Nette\\Unit\\Latte\\Fixtures\\Support\\DeclarationConsistencyFixtureTemplate}\n"
			. "{varType Exception \$narrow}\n",
		);

		try {
			$ids = $this->idsFor($dir, 'page.latte', true);

			self::assertNotContains('orisaiNette.latte.narrowingOverride', $ids);
			self::assertNotContains('orisaiNette.latte.duplicateDeclaration', $ids);
			self::assertNotContains('orisaiNette.latte.impossibleOverride', $ids);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testFileWiderOverrideReportsImpossibleOverride(): void
	{
		$dir = $this->isolatedDir('latte-consistency-file-wide');
		FileSystem::write(
			$dir . '/page.latte',
			"{templateType Tests\\OriPhpstan\\Nette\\Unit\\Latte\\Fixtures\\Support\\DeclarationConsistencyFixtureTemplate}\n"
			. "{varType Throwable \$wide}\n",
		);

		try {
			self::assertContains('orisaiNette.latte.impossibleOverride', $this->idsFor($dir, 'page.latte'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testFileUnrelatedOverrideReportsImpossibleOverride(): void
	{
		$dir = $this->isolatedDir('latte-consistency-file-unrelated');
		FileSystem::write(
			$dir . '/page.latte',
			"{templateType Tests\\OriPhpstan\\Nette\\Unit\\Latte\\Fixtures\\Support\\DeclarationConsistencyFixtureTemplate}\n"
			. "{varType int \$unrelated}\n",
		);

		try {
			self::assertContains('orisaiNette.latte.impossibleOverride', $this->idsFor($dir, 'page.latte'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	// No property named $brandNew exists on the templateType class - a varType for it is a plain
	// declaration (§2's input contract channel), never an override of anything.
	public function testFileVarTypeWithNoNativeCounterpartIsNotReported(): void
	{
		$dir = $this->isolatedDir('latte-consistency-file-no-native');
		FileSystem::write(
			$dir . '/page.latte',
			"{templateType Tests\\OriPhpstan\\Nette\\Unit\\Latte\\Fixtures\\Support\\DeclarationConsistencyFixtureTemplate}\n"
			. "{varType string \$brandNew}\n",
		);

		try {
			$ids = $this->idsFor($dir, 'page.latte');

			self::assertNotContains('orisaiNette.latte.duplicateDeclaration', $ids);
			self::assertNotContains('orisaiNette.latte.narrowingOverride', $ids);
			self::assertNotContains('orisaiNette.latte.impossibleOverride', $ids);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// A property PropertyTypeResolver can only resolve to 'mixed' (no native type hint, no @var
	// docblock) is indistinguishable from an untyped block param - same non-conflict exemption,
	// one level up.
	public function testFileUntypedNativePropertyIsNotReported(): void
	{
		$dir = $this->isolatedDir('latte-consistency-file-untyped-native');
		FileSystem::write(
			$dir . '/page.latte',
			"{templateType Tests\\OriPhpstan\\Nette\\Unit\\Latte\\Fixtures\\Support\\DeclarationConsistencyFixtureTemplate}\n"
			. "{varType string \$untyped}\n",
		);

		try {
			$ids = $this->idsFor($dir, 'page.latte');

			self::assertNotContains('orisaiNette.latte.duplicateDeclaration', $ids);
			self::assertNotContains('orisaiNette.latte.narrowingOverride', $ids);
			self::assertNotContains('orisaiNette.latte.impossibleOverride', $ids);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testFileUnresolvableOverrideTypeDegradesSilently(): void
	{
		$dir = $this->isolatedDir('latte-consistency-file-malformed');
		FileSystem::write(
			$dir . '/page.latte',
			"{templateType Tests\\OriPhpstan\\Nette\\Unit\\Latte\\Fixtures\\Support\\DeclarationConsistencyFixtureTemplate}\n"
			. "{varType int| \$exact}\n",
		);

		try {
			$ids = $this->idsFor($dir, 'page.latte');

			self::assertNotContains('orisaiNette.latte.duplicateDeclaration', $ids);
			self::assertNotContains('orisaiNette.latte.narrowingOverride', $ids);
			self::assertNotContains('orisaiNette.latte.impossibleOverride', $ids);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// === BLOCK level: definition param type (native) vs block-body top-level {varType} (override) ===

	public function testBlockExactMatchReportsDuplicateDeclaration(): void
	{
		$dir = $this->isolatedDir('latte-consistency-block-exact');
		FileSystem::write($dir . '/page.latte', "{define item, string \$x}\n{varType string \$x}\n{/define}\n");

		try {
			$diagnostics = $this->diagnosticsFor($dir, 'page.latte');
			$duplicate = $this->only($diagnostics, 'orisaiNette.latte.duplicateDeclaration');

			self::assertCount(1, $duplicate);
			self::assertSame(2, $duplicate[0]->getLatteLine());
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testBlockNarrowerOverrideReportsNarrowingOverride(): void
	{
		$dir = $this->isolatedDir('latte-consistency-block-narrow');
		FileSystem::write($dir . '/page.latte', "{define item, Throwable \$x}\n{varType Exception \$x}\n{/define}\n");

		try {
			self::assertContains('orisaiNette.latte.narrowingOverride', $this->idsFor($dir, 'page.latte'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testBlockNarrowerOverrideNotReportedWhenConfigAllowsIt(): void
	{
		$dir = $this->isolatedDir('latte-consistency-block-narrow-allowed');
		FileSystem::write($dir . '/page.latte', "{define item, Throwable \$x}\n{varType Exception \$x}\n{/define}\n");

		try {
			$ids = $this->idsFor($dir, 'page.latte', true);

			self::assertNotContains('orisaiNette.latte.narrowingOverride', $ids);
			self::assertNotContains('orisaiNette.latte.duplicateDeclaration', $ids);
			self::assertNotContains('orisaiNette.latte.impossibleOverride', $ids);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testBlockWiderOverrideReportsImpossibleOverride(): void
	{
		$dir = $this->isolatedDir('latte-consistency-block-wide');
		FileSystem::write($dir . '/page.latte', "{define item, Exception \$x}\n{varType Throwable \$x}\n{/define}\n");

		try {
			self::assertContains('orisaiNette.latte.impossibleOverride', $this->idsFor($dir, 'page.latte'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testBlockUnrelatedOverrideReportsImpossibleOverride(): void
	{
		$dir = $this->isolatedDir('latte-consistency-block-unrelated');
		FileSystem::write($dir . '/page.latte', "{define item, string \$x}\n{varType int \$x}\n{/define}\n");

		try {
			self::assertContains('orisaiNette.latte.impossibleOverride', $this->idsFor($dir, 'page.latte'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	// An own param WITHOUT a type hint (bare `$x`) has no native type to compare against - the
	// body varType is §2's contract declaration for it, not an override.
	public function testBlockUntypedNativeParamIsNotReported(): void
	{
		$dir = $this->isolatedDir('latte-consistency-block-untyped-native');
		FileSystem::write($dir . '/page.latte', "{define item, \$x}\n{varType string \$x}\n{/define}\n");

		try {
			$ids = $this->idsFor($dir, 'page.latte');

			self::assertNotContains('orisaiNette.latte.duplicateDeclaration', $ids);
			self::assertNotContains('orisaiNette.latte.narrowingOverride', $ids);
			self::assertNotContains('orisaiNette.latte.impossibleOverride', $ids);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// {block} never carries params (BlockMacros rejects them at compile time) - a body varType
	// inside a plain {block} has no native counterpart at all, same non-conflict rule as above.
	public function testBlockTagBodyVarTypeHasNoNativeCounterpartIsNotReported(): void
	{
		$dir = $this->isolatedDir('latte-consistency-block-tag-no-native');
		FileSystem::write($dir . '/page.latte', "{block item}\n{varType string \$x}\n{/block}\n");

		try {
			$ids = $this->idsFor($dir, 'page.latte');

			self::assertNotContains('orisaiNette.latte.duplicateDeclaration', $ids);
			self::assertNotContains('orisaiNette.latte.narrowingOverride', $ids);
			self::assertNotContains('orisaiNette.latte.impossibleOverride', $ids);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testBlockUnresolvableOverrideTypeDegradesSilently(): void
	{
		$dir = $this->isolatedDir('latte-consistency-block-malformed');
		FileSystem::write($dir . '/page.latte', "{define item, string \$x}\n{varType int| \$x}\n{/define}\n");

		try {
			$ids = $this->idsFor($dir, 'page.latte');

			self::assertNotContains('orisaiNette.latte.duplicateDeclaration', $ids);
			self::assertNotContains('orisaiNette.latte.narrowingOverride', $ids);
			self::assertNotContains('orisaiNette.latte.impossibleOverride', $ids);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// Native-side degrade, not just override-side: DeclarationScanner::scanTypePrefix() greedily
	// consumes a trailing `|` with no balance check, so a malformed own-param type
	// (`{define item, string| $x}`) produces the raw type string "string|" - TypeStringResolver
	// throws ParserException resolving it, exercising compare()'s FIRST try/catch (the native one),
	// independent from and not exercised by the override-side malformed tests above.
	public function testBlockUnresolvableNativeTypeDegradesSilently(): void
	{
		$dir = $this->isolatedDir('latte-consistency-block-malformed-native');
		FileSystem::write($dir . '/page.latte', "{define item, string| \$x}\n{varType string \$x}\n{/define}\n");

		try {
			$ids = $this->idsFor($dir, 'page.latte');

			self::assertNotContains('orisaiNette.latte.duplicateDeclaration', $ids);
			self::assertNotContains('orisaiNette.latte.narrowingOverride', $ids);
			self::assertNotContains('orisaiNette.latte.impossibleOverride', $ids);
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @return list<string>
	 */
	private function idsFor(string $dir, string $basename, bool $allowNarrowingOverride = false): array
	{
		return array_map(
			static fn (Diagnostic $d): string => $d->getIdentifier(),
			$this->diagnosticsFor($dir, $basename, $allowNarrowingOverride),
		);
	}

	/**
	 * @return list<Diagnostic>
	 */
	private function diagnosticsFor(string $dir, string $basename, bool $allowNarrowingOverride = false): array
	{
		$absoluteFile = $dir . '/' . $basename;
		$universe = new LatteUniverse([$dir], $dir);
		$index = new TemplateEdgeIndex($universe, TestAdapter::accessor());
		$declarations = (new DeclarationScanner())->scan(FileSystem::read($absoluteFile));
		$checker = new DeclarationConsistencyChecker(
			$index,
			self::getContainer()->getByType(TypeStringResolver::class),
			$allowNarrowingOverride,
		);

		return $checker->check($declarations, $absoluteFile, $basename);
	}

	/**
	 * @param list<Diagnostic> $diagnostics
	 * @return list<Diagnostic>
	 */
	private function only(array $diagnostics, string $identifier): array
	{
		return array_values(array_filter(
			$diagnostics,
			static fn (Diagnostic $d): bool => $d->getIdentifier() === $identifier,
		));
	}

	private function isolatedDir(string $prefix): string
	{
		return sys_get_temp_dir() . '/' . $prefix . '-test-' . getmypid() . '-' . uniqid('', true);
	}

}
