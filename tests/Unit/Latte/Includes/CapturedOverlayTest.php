<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Includes\CapturedOverlay;
use OriPhpstan\Nette\Latte\Includes\SiteScopeStore;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function getmypid;
use function sha1;
use function sys_get_temp_dir;
use function uniqid;

final class CapturedOverlayTest extends BaseTestCase
{

	public function testEnabledOverlayReplacesAMatchingVarType(): void
	{
		$dir = $this->scratchDir();

		try {
			$includer = $dir . '/includer.latte';
			FileSystem::write($includer, "irrelevant\n");
			$store = $this->storeWith($dir, $includer, ['x' => 'Exception']);

			$overlay = new CapturedOverlay($store, true);
			$result = $overlay->overlay(
				['x' => 'Exception|null'],
				[],
				'includer.latte',
				$includer,
				1,
				"'t.latte'",
				'ctx',
			);

			self::assertSame(
				['x' => 'Exception'],
				$result['vars'],
				'enabled: true must apply the store entry that matches the includer sha',
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testCaptureEqualModuloLeadingBackslashNarrowsNothing(): void
	{
		$dir = $this->scratchDir();

		try {
			$includer = $dir . '/includer.latte';
			FileSystem::write($includer, "irrelevant\n");
			$store = $this->storeWith($dir, $includer, ['control' => 'App\\UserPresenter', 'x' => 'Exception']);

			$overlay = new CapturedOverlay($store, true);
			$result = $overlay->overlay(
				['control' => '\\App\\UserPresenter', 'x' => 'Exception|null'],
				[],
				'includer.latte',
				$includer,
				1,
				"'t.latte'",
				'ctx',
			);

			self::assertSame(['control' => '\\App\\UserPresenter', 'x' => 'Exception'], $result['vars']);
			self::assertSame(['x' => true], $result['overlaidNames']);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// Opt-in gate: a disabled overlay must ignore a store entry that WOULD
	// narrow the type if enabled - "no store reads" is provable exactly here, since the store
	// genuinely contains a matching, valid entry and the overlay still must not apply it.
	public function testDisabledOverlayIgnoresAMatchingStoreEntry(): void
	{
		$dir = $this->scratchDir();

		try {
			$includer = $dir . '/includer.latte';
			FileSystem::write($includer, "irrelevant\n");
			$store = $this->storeWith($dir, $includer, ['x' => 'Exception']);

			$overlay = new CapturedOverlay($store, false);
			$result = $overlay->overlay(
				['x' => 'Exception|null'],
				[],
				'includer.latte',
				$includer,
				1,
				"'t.latte'",
				'ctx',
			);

			self::assertSame(
				['x' => 'Exception|null'],
				$result['vars'],
				'enabled: false must leave the wide (edge-provided) type untouched, never read the store',
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testDisabledGetSliceAlwaysReturnsNullEvenForAMatchingEntry(): void
	{
		$dir = $this->scratchDir();

		try {
			$includer = $dir . '/includer.latte';
			FileSystem::write($includer, "irrelevant\n");
			$store = $this->storeWith($dir, $includer, ['x' => 'Exception']);

			$overlay = new CapturedOverlay($store, false);

			self::assertNull(
				$overlay->getSlice('includer.latte', $includer, 1, "'t.latte'", 'ctx'),
				'DeclarationInjector\'s direct getSlice() call must also see nothing when disabled',
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	// DeclaredVarsResolver::forBlock's names must count as DECLARED for this consume-side filter
	// too - defensive against an old-store-version entry captured before a block body declared
	// {varType} for that name (block-body {varType} is new syntax, so any pre-existing slice
	// predates it by construction). Both the 'vars' bucket (ContextResolver/IncludeContractChecker's
	// overlay() channel) and the 'args' bucket (DeclarationInjector's own direct getSlice() read,
	// capturedBlockArgTypes) get the same treatment - callers that never pass $declaredNames (every
	// pre-existing call site) see no change at all.

	public function testDeclaredNameIsStrippedFromSliceVarsEvenWithAMatchingStoreEntry(): void
	{
		$dir = $this->scratchDir();

		try {
			$includer = $dir . '/includer.latte';
			FileSystem::write($includer, "irrelevant\n");
			$store = $this->storeWith($dir, $includer, ['x' => 'Exception', 'y' => 'stdClass']);

			$overlay = new CapturedOverlay($store, true);
			$slice = $overlay->getSlice('includer.latte', $includer, 1, "'t.latte'", 'ctx', ['x' => 'Foo']);

			self::assertNotNull($slice);
			self::assertArrayNotHasKey(
				'x',
				$slice['vars'],
				'a declared name must never surface in a consumed slice, even with a matching store entry',
			);
			self::assertSame(
				'stdClass',
				$slice['vars']['y'] ?? null,
				'an undeclared name in the same slice must stay untouched (no over-exclusion)',
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testDeclaredNameIsStrippedFromSliceArgsEvenWithAMatchingStoreEntry(): void
	{
		$dir = $this->scratchDir();

		try {
			$includer = $dir . '/includer.latte';
			FileSystem::write($includer, "irrelevant\n");
			$store = $this->storeWithArgs($dir, $includer, ['inner' => 'non-falsy-string', 'other' => 'int']);

			$overlay = new CapturedOverlay($store, true);
			$slice = $overlay->getSlice('includer.latte', $includer, 1, 'greet', 'ctx', ['inner' => 'Foo']);

			self::assertNotNull($slice);
			self::assertArrayNotHasKey(
				'inner',
				$slice['args'],
				'capturedBlockArgTypes must never see a captured type for a body-varType-declared own param',
			);
			self::assertSame('int', $slice['args']['other'] ?? null, 'an undeclared arg name stays untouched');
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testNoDeclaredNamesLeavesSliceUnchangedForExistingCallers(): void
	{
		$dir = $this->scratchDir();

		try {
			$includer = $dir . '/includer.latte';
			FileSystem::write($includer, "irrelevant\n");
			$store = $this->storeWith($dir, $includer, ['x' => 'Exception']);

			$overlay = new CapturedOverlay($store, true);
			$slice = $overlay->getSlice('includer.latte', $includer, 1, "'t.latte'", 'ctx');

			self::assertNotNull($slice);
			self::assertSame(
				['x' => 'Exception'],
				$slice['vars'],
				'default (omitted) $declaredNames must filter nothing',
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @param array<string, string> $vars
	 */
	private function storeWith(string $dir, string $includerAbsolute, array $vars): SiteScopeStore
	{
		$storeDir = $dir . '/store';
		SiteScopeStore::bootstrap($storeDir, []);

		$store = new SiteScopeStore($storeDir);
		$key = SiteScopeStore::key('includer.latte', 1, "'t.latte'", 'ctx');
		$store->replaceForIncluders(
			['includer.latte'],
			[$key => ['sha' => sha1(FileSystem::read($includerAbsolute)), 'vars' => $vars, 'args' => []]],
		);

		return $store;
	}

	/**
	 * @param array<string, string> $args
	 */
	private function storeWithArgs(string $dir, string $includerAbsolute, array $args): SiteScopeStore
	{
		$storeDir = $dir . '/store';
		SiteScopeStore::bootstrap($storeDir, []);

		$store = new SiteScopeStore($storeDir);
		$key = SiteScopeStore::key('includer.latte', 1, 'greet', 'ctx');
		$store->replaceForIncluders(
			['includer.latte'],
			[$key => ['sha' => sha1(FileSystem::read($includerAbsolute)), 'vars' => [], 'args' => $args]],
		);

		return $store;
	}

	private function scratchDir(): string
	{
		$dir = sys_get_temp_dir() . '/latte-captured-overlay-test-' . getmypid() . '-' . uniqid('', true);
		FileSystem::createDir($dir);

		return $dir;
	}

}
