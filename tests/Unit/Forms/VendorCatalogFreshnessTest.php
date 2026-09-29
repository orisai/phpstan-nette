<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms;

use Composer\InstalledVersions;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Catalog\Stub\VendorCatalogFreshness;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function implode;
use function preg_replace;
use function str_replace;
use function sys_get_temp_dir;
use function uniqid;

final class VendorCatalogFreshnessTest extends BaseTestCase
{

	private const PACKAGE_COPY = 'nette-forms';

	private const VENDOR_CHOICE_CONTROL = self::PACKAGE_COPY . '/src/Forms/Controls/ChoiceControl.php';

	private const VENDOR_CONTAINER = self::PACKAGE_COPY . '/src/Forms/Container.php';

	private const VENDOR_CHOICE_CONTROL_DESCRIPTION = ' * Choice control that allows single item selection.';

	/** @var list<string> */
	private array $throwawayRoots = [];

	protected function tearDown(): void
	{
		foreach ($this->throwawayRoots as $root) {
			FileSystem::delete($root);
		}

		$this->throwawayRoots = [];

		parent::tearDown();
	}

	public function testTheCommittedVendorFactsStillMatchTheInstalledSource(): void
	{
		$drifts = (new VendorCatalogFreshness())->drifts();

		self::assertSame(
			[],
			$drifts,
			"The forms extension's vendor-derived facts are stale:\n\n" . implode("\n\n", $drifts),
		);
	}

	/**
	 * The gate has to read vendor SOURCE. Once control-value-types.stub is applied, PHPStan answers
	 * every reflected docblock question about ChoiceControl with the STUB's docblock, so a
	 * reflection-based check would be comparing the stub against itself and could never fail. Proven
	 * here by mutating a copy of the vendor source and watching the drift appear.
	 */
	public function testAPropertyTagVendorAddsToARedeclaredClassIsReported(): void
	{
		$root = $this->rootWithMutated(
			self::VENDOR_CHOICE_CONTROL,
			self::VENDOR_CHOICE_CONTROL_DESCRIPTION,
			self::VENDOR_CHOICE_CONTROL_DESCRIPTION . "\n *\n * @property-read string \$prompt",
		);

		$drifts = (new VendorCatalogFreshness($root, $root . '/' . self::PACKAGE_COPY))->drifts();

		self::assertCount(1, $drifts);
		self::assertStringContainsString('property-read $prompt', $drifts[0]);
		self::assertStringContainsString(VendorCatalogFreshness::STUB_FIX_HINT, $drifts[0]);
	}

	/**
	 * nette/forms 3.3 demotes `@property-read $selectedItem` to Nette's own `@property-deprecated`, which
	 * PHPStan does not read, so redeclaring the class drops nothing and the stub keeps its own tag.
	 */
	public function testANetteOnlyDeprecatedPropertyTagIsNotGated(): void
	{
		$root = $this->rootWithMutated(
			self::VENDOR_CHOICE_CONTROL,
			self::VENDOR_CHOICE_CONTROL_DESCRIPTION,
			self::VENDOR_CHOICE_CONTROL_DESCRIPTION . "\n *\n * @property-deprecated string \$prompt",
		);

		self::assertSame([], (new VendorCatalogFreshness($root, $root . '/' . self::PACKAGE_COPY))->drifts());
	}

	public function testDroppingARestatedPropertyTagFromOurStubIsReported(): void
	{
		$root = $this->rootWithMutated(
			VendorCatalogFreshness::STUB,
			' * @property array<int|string, mixed> $items
 * @property-read mixed $selectedItem',
			' * @property-read mixed $selectedItem',
		);

		$drifts = (new VendorCatalogFreshness($root, $root . '/' . self::PACKAGE_COPY))->drifts();

		self::assertCount(1, $drifts);
		self::assertStringContainsString('property $items', $drifts[0]);
	}

	public function testAVendorFactoryTheCatalogTypesAndVendorNoLongerDeclaresIsReported(): void
	{
		$root = $this->rootWithMutated(
			self::VENDOR_CONTAINER,
			'public function addColor(',
			'public function addColour(',
		);

		$drifts = (new VendorCatalogFreshness($root, $root . '/' . self::PACKAGE_COPY))->drifts();

		self::assertCount(1, $drifts);
		self::assertStringContainsString('addColor()', $drifts[0]);
		self::assertStringContainsString(VendorCatalogFreshness::CATALOG_FIX_HINT, $drifts[0]);
	}

	/**
	 * The control class is no longer restated anywhere — it is read live off the vendor factory's own
	 * declared return type — so a factory that stops declaring one silently unclasses its catalog
	 * entry, and that is what this catches.
	 */
	public function testAVendorFactoryThatStopsReturningAControlIsReported(): void
	{
		$root = $this->rootWithMutated(
			self::VENDOR_CONTAINER,
			'~(public function addSelect\([^)]*\))\s*:\s*Controls\\\\SelectBox~',
			'$1',
			true,
		);

		$drifts = (new VendorCatalogFreshness($root, $root . '/' . self::PACKAGE_COPY))->drifts();

		self::assertCount(1, $drifts);
		self::assertStringContainsString('no declared return type', $drifts[0]);
	}

	public function testACatalogThatNoLongerDeclaresTheExpectedInterfaceIsReported(): void
	{
		$root = $this->rootWithMutated(
			VendorCatalogFreshness::CATALOG,
			'interface FormValueTypeCatalog',
			'interface FormValueTypeCatalogRenamed',
		);

		$drifts = (new VendorCatalogFreshness($root, $root . '/' . self::PACKAGE_COPY))->drifts();

		self::assertCount(1, $drifts);
		self::assertStringContainsString('declares no factory at all', $drifts[0]);
	}

	/**
	 * A throwaway project root holding copies of everything the gate reads, one file mutated.
	 * Copying rather than editing in place keeps the real vendor tree and the committed catalog
	 * untouched even when an assertion fails mid-test.
	 */
	private function rootWithMutated(string $relativePath, string $search, string $replace, bool $regex = false): string
	{
		$real = __DIR__ . '/../../..';
		$root = sys_get_temp_dir() . '/' . uniqid('forms-vendor-freshness-', true);
		$this->throwawayRoots[] = $root;

		$installed = InstalledVersions::getInstallPath(VendorCatalogFreshness::PACKAGE);
		self::assertNotNull($installed);
		FileSystem::copy($installed, $root . '/' . self::PACKAGE_COPY);

		foreach ([VendorCatalogFreshness::STUB, VendorCatalogFreshness::CATALOG] as $file) {
			FileSystem::copy($real . '/' . $file, $root . '/' . $file);
		}

		$source = FileSystem::read($root . '/' . $relativePath);
		$mutated = $regex
			? (string) preg_replace($search, $replace, $source, 1)
			: str_replace($search, $replace, $source);
		self::assertNotSame($source, $mutated, 'the mutation must actually apply');
		FileSystem::write($root . '/' . $relativePath, $mutated);

		return $root;
	}

}
