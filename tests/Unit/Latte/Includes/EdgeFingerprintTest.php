<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Compile\ProjectRelativePath;
use OriPhpstan\Nette\Latte\Includes\EdgeFingerprint;
use OriPhpstan\Nette\Latte\Includes\IncludeTarget;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Includes\TemplateEdgeIndex;
use OriPhpstan\Nette\Latte\Includes\TemplateFactExtractor;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function array_map;
use function dirname;
use function uniqid;

final class EdgeFingerprintTest extends BaseTestCase
{

	// A fixed placeholder for tests that aren't exercising the slice-hash window itself - real
	// callers always pass SiteScopeStore::sliceHash()'s output, but any fixed string proves these
	// unrelated windows still work now that the parameter is required.
	private const NoSlice = '';

	public function testEmptySitesIsStable(): void
	{
		self::assertSame(
			EdgeFingerprint::compute([], [], [], self::NoSlice),
			EdgeFingerprint::compute([], [], [], self::NoSlice),
		);
	}

	public function testSameSitesProduceSameFingerprintRegardlessOfInputOrder(): void
	{
		$a = $this->site('include', 'partial.latte', 'w => $n', 1);
		$b = $this->site('include', 'other.latte', '', 2);

		self::assertSame(
			EdgeFingerprint::compute([$a, $b], [], [], self::NoSlice),
			EdgeFingerprint::compute([$b, $a], [], [], self::NoSlice),
			'input order must not affect the fingerprint - only the sorted (line, tag, ...) sequence does',
		);
	}

	public function testArgsSourceEditChangesFingerprint(): void
	{
		$before = EdgeFingerprint::compute(
			[$this->site('include', 'partial.latte', 'w => $n', 1)],
			[],
			[],
			self::NoSlice,
		);
		$after = EdgeFingerprint::compute([$this->site('include', 'partial.latte', '', 1)], [], [], self::NoSlice);

		self::assertNotSame($before, $after, 'dropping an include argument must change the fingerprint');
	}

	public function testRawTargetEditChangesFingerprint(): void
	{
		$before = EdgeFingerprint::compute(
			[$this->site('include', 'partial.latte', 'w => $n', 1)],
			[],
			[],
			self::NoSlice,
		);
		$after = EdgeFingerprint::compute(
			[$this->site('include', 'other-partial.latte', 'w => $n', 1)],
			[],
			[],
			self::NoSlice,
		);

		self::assertNotSame($before, $after);
	}

	public function testTagEditChangesFingerprint(): void
	{
		$before = EdgeFingerprint::compute([$this->site('include', 'partial.latte', '', 1)], [], [], self::NoSlice);
		$after = EdgeFingerprint::compute([$this->site('embed', 'partial.latte', '', 1)], [], [], self::NoSlice);

		self::assertNotSame($before, $after);
	}

	public function testResolvedPathNullVersusEmptyDoesNotCollide(): void
	{
		$dynamic = new IncludeTarget('include', IncludeTarget::KIND_DYNAMIC, '$tpl', null, '', 1);
		$staticEmpty = new IncludeTarget('include', IncludeTarget::KIND_DYNAMIC, '$tpl', '', '', 1);

		self::assertNotSame(
			EdgeFingerprint::compute([$dynamic], [], [], self::NoSlice),
			EdgeFingerprint::compute([$staticEmpty], [], [], self::NoSlice),
		);
	}

	// (b) window: a target's {layout}/{extends} edge reads the INCLUDER's topLevelVars
	// (EdgeScope), which are never one of the includer's own outgoing sites - closing this means
	// the includer's own fingerprint must change when one of its top-level {var} assignments does.
	public function testTopLevelVarEditChangesFingerprint(): void
	{
		$sites = [$this->site('layout', '@layout.latte', '', 1)];

		$before = EdgeFingerprint::compute($sites, ['childVar' => 'int'], [], self::NoSlice);
		$after = EdgeFingerprint::compute($sites, ['childVar' => 'string'], [], self::NoSlice);

		self::assertNotSame($before, $after, 'a top-level {var} type edit must change the fingerprint');
	}

	// (c) window: any includer's missing-variable check reads the TARGET's topLevelDefaults
	// (IncludeContractChecker::defaultNamesFor); a target's own fingerprint must therefore change
	// when a top-level {default} is added or removed, even with its outgoing sites unchanged.
	public function testTopLevelDefaultEditChangesFingerprint(): void
	{
		$sites = [$this->site('include', 'partial.latte', '', 1)];

		$before = EdgeFingerprint::compute($sites, [], [], self::NoSlice);
		$after = EdgeFingerprint::compute($sites, [], ['must'], self::NoSlice);

		self::assertNotSame($before, $after, 'gaining a top-level {default} must change the fingerprint');
	}

	// A file's own $templateTypeVars fold closes the transitive-includer
	// window - every {templateType} class reachable via this file's own outgoing include graph
	// (not just its own declaration) folds its resolved property shape in here, so a change to
	// that shape changes THIS file's own exported fingerprint value even when nothing in this
	// file's own .latte bytes changed. Optional/defaulted (`[]`) for backward compatibility with
	// every 4-arg call site above.
	public function testTemplateTypeVarEditChangesFingerprint(): void
	{
		$sites = [$this->site('include', 'partial.latte', '', 1)];

		$before = EdgeFingerprint::compute($sites, [], [], self::NoSlice, ['n' => 'int']);
		$after = EdgeFingerprint::compute($sites, [], [], self::NoSlice, ['n' => 'string']);

		self::assertNotSame($before, $after, 'a {templateType} class property retype must change the fingerprint');
	}

	public function testOmittingTemplateTypeVarsProducesTheSameFingerprintAsAnExplicitEmptyArray(): void
	{
		$sites = [$this->site('include', 'partial.latte', '', 1)];

		self::assertSame(
			EdgeFingerprint::compute($sites, [], [], self::NoSlice),
			EdgeFingerprint::compute($sites, [], [], self::NoSlice, []),
		);
	}

	public function testTemplateTypeVarsOrderDoesNotAffectFingerprint(): void
	{
		$sites = [$this->site('include', 'partial.latte', '', 1)];

		self::assertSame(
			EdgeFingerprint::compute($sites, [], [], self::NoSlice, ['a' => 'int', 'b' => 'string']),
			EdgeFingerprint::compute($sites, [], [], self::NoSlice, ['b' => 'string', 'a' => 'int']),
		);
	}

	public function testTopLevelVarsOrderDoesNotAffectFingerprint(): void
	{
		$sites = [$this->site('layout', '@layout.latte', '', 1)];

		self::assertSame(
			EdgeFingerprint::compute($sites, ['a' => 'int', 'b' => 'string'], [], self::NoSlice),
			EdgeFingerprint::compute($sites, ['b' => 'string', 'a' => 'int'], [], self::NoSlice),
		);
	}

	// This file's own SiteScopeStore slice (captures written
	// FOR it) must fold into the exported fingerprint, or a capture-only change (no site/var/default
	// edit) would leave PHPStan's exportedNodesChanged() blind to it.
	public function testSiteScopeSliceHashEditChangesFingerprint(): void
	{
		$sites = [$this->site('include', 'partial.latte', '', 1)];

		$before = EdgeFingerprint::compute($sites, [], [], 'slice-hash-a');
		$after = EdgeFingerprint::compute($sites, [], [], 'slice-hash-b');

		self::assertNotSame($before, $after, 'a changed store slice hash must change the fingerprint');
	}

	public function testIdenticalSiteScopeSliceHashProducesIdenticalFingerprint(): void
	{
		$sites = [$this->site('include', 'partial.latte', '', 1)];

		self::assertSame(
			EdgeFingerprint::compute($sites, [], [], 'same-slice-hash'),
			EdgeFingerprint::compute($sites, [], [], 'same-slice-hash'),
		);
	}

	// Exercises the determinism contract through the real TemplateEdgeIndex/LatteUniverse pipeline
	// (not just EdgeFingerprint::compute() in isolation): a fresh index rebuilt from disk after an
	/**
	 * @group latte2
	 */
	// UNRELATED neighbor file's edit must still yield byte-identical outgoingSites()+fingerprint for
	// a file that never references that neighbor - this must keep holding with the widened
	// (topLevelVars/topLevelDefaults) input too, since those are this file's OWN facts only.
	public function testUnrelatedNeighborSiteDoesNotAffectFingerprintOfDisjointSet(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$dir = $projectRoot . '/var/tmp/latte-edgefp-test-' . uniqid('', true);
		FileSystem::createDir($dir);

		try {
			FileSystem::write(
				$dir . '/fileA.latte',
				"{varType int \$n}\n{default \$n = 1}\n{include 'fileB.latte', w => 1}\n",
			);
			FileSystem::write($dir . '/fileB.latte', "{\$w}\n");
			FileSystem::write($dir . '/fileC.latte', "unrelated\n");

			$before = $this->outgoingFingerprintFor($dir, $projectRoot, 'fileA.latte');

			// fileC.latte is never referenced by fileA.latte's own sites.
			FileSystem::write($dir . '/fileC.latte', "unrelated - edited\n");

			$after = $this->outgoingFingerprintFor($dir, $projectRoot, 'fileA.latte');

			self::assertSame($before, $after);
		} finally {
			FileSystem::delete($dir);
		}
	}

	private function site(string $tag, string $rawTarget, string $argsSource, int $line): IncludeTarget
	{
		return new IncludeTarget($tag, IncludeTarget::KIND_STATIC_FILE, $rawTarget, $rawTarget, $argsSource, $line);
	}

	/**
	 * @return array{sites: list<array{tag: string, kind: string, rawTarget: string, resolvedPath: string|null, argsSource: string, latteLine: int}>, fingerprint: string}
	 */
	private function outgoingFingerprintFor(string $dir, string $projectRoot, string $basename): array
	{
		// A FRESH LatteUniverse/TemplateEdgeIndex per call - not a shared/cached instance - so this
		// proves the contract from disk, not merely from a memoized in-process value.
		$universe = new LatteUniverse([$dir], $projectRoot);
		$index = new TemplateEdgeIndex($universe, new TemplateFactExtractor());
		$rel = ProjectRelativePath::relativize($projectRoot, $dir . '/' . $basename);
		$absolute = $dir . '/' . $basename;
		$sites = $index->outgoingSites($rel);
		$facts = $index->factsFor($absolute);

		return [
			'sites' => array_map(static fn (IncludeTarget $site): array => $site->toArray(), $sites),
			'fingerprint' => EdgeFingerprint::compute(
				$sites,
				$facts->getTopLevelVars(),
				$facts->getTopLevelDefaults(),
				self::NoSlice,
			),
		];
	}

}
