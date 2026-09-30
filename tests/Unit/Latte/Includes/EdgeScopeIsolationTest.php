<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Compile\Diagnostic;
use OriPhpstan\Nette\Latte\Includes\ArgTyper;
use OriPhpstan\Nette\Latte\Includes\CapturedOverlay;
use OriPhpstan\Nette\Latte\Includes\ContextResolver;
use OriPhpstan\Nette\Latte\Includes\EdgeScope;
use OriPhpstan\Nette\Latte\Includes\IncludeContractChecker;
use OriPhpstan\Nette\Latte\Includes\IncludeTarget;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Includes\SiteScopeStore;
use OriPhpstan\Nette\Latte\Includes\TemplateContext;
use OriPhpstan\Nette\Latte\Includes\TemplateEdgeIndex;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Testing\PHPStanTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;
use function array_map;
use function getmypid;
use function sys_get_temp_dir;
use function uniqid;

// orisai.nette.latte.includeIsolation switches EdgeScope::resolve() from the union every Latte line runs
// (includer's scope + explicit args) to explicit args only. The probes fix what the flag changes
// (file-form include/embed) and what it must not (block dispatch, layout/extends, import, sandbox,
// namedKeys/open).
/**
 * @group latte2
 */
final class EdgeScopeIsolationTest extends PHPStanTestCase
{

	use VersionGroupGate;

	public function testFileIncludeUnionsAmbientScopeWithExplicitArgsWhenIsolationIsOff(): void
	{
		$scope = $this->resolveFileInclude('extra: $obj', false);

		self::assertSame(['ambient' => 'string', 'extra' => 'mixed'], $scope['vars']);
		self::assertSame(['extra' => 'mixed'], $scope['namedKeys']);
		self::assertFalse($scope['open']);
	}

	public function testFileIncludeDropsAmbientScopeWhenIsolationIsOn(): void
	{
		$scope = $this->resolveFileInclude('extra: $obj', true);

		self::assertSame(['extra' => 'mixed'], $scope['vars']);
		self::assertSame(['extra' => 'mixed'], $scope['namedKeys']);
		self::assertFalse($scope['open']);
	}

	public function testIsolationKeepsTheOpenFlagOfAnUnenumerableArgList(): void
	{
		$scope = $this->resolveFileInclude('(expand) $args', true);

		self::assertSame([], $scope['vars']);
		self::assertSame([], $scope['namedKeys']);
		self::assertTrue($scope['open'], 'a spread arg list stays OPEN under isolation - the target'
			. ' may still receive names this analysis cannot enumerate, so definite-NO checks must'
			. ' keep standing down exactly as they do under union semantics');
	}

	public function testEmbedFileFormIsolatesToo(): void
	{
		$scope = EdgeScope::resolve(
			new IncludeTarget('embed', IncludeTarget::KIND_STATIC_FILE, 'part.latte', 'part.latte', 'extra: $obj', 1),
			TemplateContext::root(['ambient' => 'string']),
			new ArgTyper(TestAdapter::accessor()),
			static fn (): array => [],
			true,
		);

		self::assertSame(['extra' => 'mixed'], $scope['vars']);
	}

	// A block dispatch is a wholly different vendor mechanism from a file include: BlockMacros
	// compiles {include #block} to renderBlock($name, get_defined_vars() + ...) in Latte 2 and
	// still hands the surrounding scope to the block in Latte 3. Only the FILE form is what Latte 3
	// isolates, so a block site must read identically in both flag states.
	public function testBlockDispatchIsUnaffectedByIsolation(): void
	{
		$site = new IncludeTarget('include', IncludeTarget::KIND_STATIC_BLOCK, 'row', null, 'extra: $obj', 1);
		$context = TemplateContext::root(['ambient' => 'string']);

		self::assertSame(
			EdgeScope::resolve(
				$site,
				$context,
				new ArgTyper(TestAdapter::accessor()),
				static fn (): array => [],
				false,
			),
			EdgeScope::resolve($site, $context, new ArgTyper(TestAdapter::accessor()), static fn (): array => [], true),
		);
	}

	public function testLayoutImportAndSandboxAreUnaffectedByIsolation(): void
	{
		$context = TemplateContext::root(['ambient' => 'string']);

		foreach (['layout', 'extends', 'import', 'sandbox'] as $tag) {
			$site = new IncludeTarget($tag, IncludeTarget::KIND_STATIC_FILE, 'p.latte', 'p.latte', 'extra: $obj', 1);

			self::assertSame(
				EdgeScope::resolve(
					$site,
					$context,
					new ArgTyper(TestAdapter::accessor()),
					static fn (): array => ['local' => 'int'],
					false,
				),
				EdgeScope::resolve(
					$site,
					$context,
					new ArgTyper(TestAdapter::accessor()),
					static fn (): array => ['local' => 'int'],
					true,
				),
				'{' . $tag . '} must read identically in both flag states',
			);
		}
	}

	public function testIsolationTurnsAnInheritedVariableIntoAnIncludeMissingVariableFinding(): void
	{
		$dir = sys_get_temp_dir() . '/latte-isolation-test-' . getmypid() . '-' . uniqid('', true);

		FileSystem::write($dir . '/includer.latte', "{varType string \$shared}\n{include 'partial.latte'}\n");
		FileSystem::write($dir . '/partial.latte', "{varType string \$shared}\n{\$shared}\n");

		try {
			self::assertNotContains(
				'orisaiNette.latte.includeMissingVariable',
				$this->identifiersFor($dir, false),
				'union semantics: the includer\'s own $shared reaches the partial without the include'
					. ' site naming it',
			);
			self::assertContains(
				'orisaiNette.latte.includeMissingVariable',
				$this->identifiersFor($dir, true),
				'isolation: the same edge provides nothing but its (empty) explicit args, so the'
					. ' partial\'s declared $shared is unprovided - this finding IS the Latte 3'
					. ' migration worklist',
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @return array{vars: array<string, string>, namedKeys: array<string, string>, open: bool}
	 */
	private function resolveFileInclude(string $argsSource, bool $includeIsolation): array
	{
		return EdgeScope::resolve(
			new IncludeTarget('include', IncludeTarget::KIND_STATIC_FILE, 'part.latte', 'part.latte', $argsSource, 1),
			TemplateContext::root(['ambient' => 'string']),
			new ArgTyper(TestAdapter::accessor()),
			static fn (): array => [],
			$includeIsolation,
		);
	}

	/**
	 * @return list<string>
	 */
	private function identifiersFor(string $dir, bool $includeIsolation): array
	{
		$universe = new LatteUniverse([$dir], $dir);
		$index = new TemplateEdgeIndex($universe, TestAdapter::accessor());
		$capturedOverlay = new CapturedOverlay(new SiteScopeStore($dir . '/__absent_sitescope_store__'), false);
		$resolver = new ContextResolver(
			$index,
			$universe,
			$capturedOverlay,
			$includeIsolation,
		);
		$checker = new IncludeContractChecker(
			$index,
			$universe,
			$resolver,
			self::getContainer()->getByType(TypeStringResolver::class),
			$capturedOverlay,
			$includeIsolation,
		);

		return array_map(
			static fn (Diagnostic $diagnostic): string => $diagnostic->getIdentifier(),
			$checker->check('includer.latte', $resolver->contextsFor('includer.latte')),
		);
	}

}
