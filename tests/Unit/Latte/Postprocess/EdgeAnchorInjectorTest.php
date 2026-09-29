<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Postprocess;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Compile\LatteCompiler;
use OriPhpstan\Nette\Latte\Declarations\DeclarationScanner;
use OriPhpstan\Nette\Latte\Includes\TemplateContext;
use OriPhpstan\Nette\Latte\Postprocess\AnalysisPipeline;
use OriPhpstan\Nette\Latte\Postprocess\EdgeAnchorInjector;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use ReflectionProperty;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\PipelineFactory;
use function array_map;
use function count;
use function preg_quote;
use function strpos;
use function sys_get_temp_dir;
use function uniqid;

/**
 * @group latte2
 */
final class EdgeAnchorInjectorTest extends BaseTestCase
{

	public function testFileIncludeUnderIfCarriesKeyManifestAndCompoundArg(): void
	{
		$this->withProject(
			[
				'includer.latte' => "{varType string \$user}\n{varType string \$order}\n\n"
					. "{if \$user !== null}\n\t{include 'x.latte', item: \$order->getItem()}\n{/if}\n",
				'x.latte' => "{\$item}\n",
			],
			function (string $projectRoot): void {
				$contexts = $this->resolveContexts($projectRoot, 'includer.latte');
				self::assertCount(1, $contexts, 'includer.latte has no incoming edges: exactly one root context');
				$expectedHash = $contexts[0]->canonicalHash();

				$call = $this->findEdgeScopeCall($this->processIncluder($projectRoot, 'includer.latte'), 'x.latte');

				self::assertNotNull($call);
				$key = $this->stringArg($call, 0);
				self::assertMatchesRegularExpression(
					'~^includer\.latte#\d+#x\.latte#' . preg_quote($expectedHash, '~') . '$~',
					$key,
					'store key must carry the includer context\'s own canonical hash, not an ordinal',
				);

				$manifest = $call->getAttribute('latte.edgeManifest');
				self::assertIsArray($manifest);
				self::assertSame(
					['order', 'user'],
					$manifest,
					'full manifest: both ambient varType declarations undeclared by x.latte, excluding the explicit "item" arg',
				);

				$args = $this->argKeys($call);
				self::assertSame(['item'], $args);
			},
		);
	}

	public function testLayoutAnchorIsLastReachableStatementOfMain(): void
	{
		$this->withProject(
			[
				'child.latte' => "{varType string \$title}\n{layout 'my-layout.latte'}\n\n{\$title}\n",
				'my-layout.latte' => "{\$title}\n",
			],
			function (string $projectRoot): void {
				$stmts = $this->processIncluder($projectRoot, 'child.latte');
				$call = $this->findEdgeScopeCall($stmts, 'my-layout.latte');

				self::assertNotNull($call);

				$manifest = $call->getAttribute('latte.edgeManifest');
				self::assertIsArray($manifest);
				self::assertContains('title', $manifest);

				$method = $this->findMethod($stmts, 'latteMain_ctx0');
				self::assertNotNull($method);
				self::assertNotNull($method->stmts);

				$count = count($method->stmts);
				self::assertInstanceOf(
					Return_::class,
					$method->stmts[$count - 1],
					'return stays the true last statement',
				);

				$anchorStmt = $method->stmts[$count - 2];
				self::assertInstanceOf(Node\Stmt\Expression::class, $anchorStmt);
				self::assertSame($call, $anchorStmt->expr);
			},
		);
	}

	public function testFullyDeclaredTargetWithNoArgsProducesNoAnchor(): void
	{
		$this->withProject(
			[
				'includer2.latte' => "{varType string \$known}\n\n{include 'declared.latte'}\n",
				'declared.latte' => "{varType string \$known}\n\n{\$known}\n",
			],
			function (string $projectRoot): void {
				$stmts = $this->processIncluder($projectRoot, 'includer2.latte');
				$call = $this->findEdgeScopeCall($stmts, 'declared.latte');

				self::assertNull($call, 'fully-declared target with no args must not get an anchor');
			},
		);
	}

	public function testMultipleContextsGetDistinctAnchorsPerClone(): void
	{
		$this->withProject(
			[
				'partial.latte' => "{varType string \$n}\n\n{include 'leaf.latte', v: \$n}\n",
				'leaf.latte' => "{\$v}\n",
				'root-a.latte' => "{include 'partial.latte', n: 'a'}\n",
				'root-b.latte' => "{include 'partial.latte', n: 'a', extra: 1}\n",
			],
			function (string $projectRoot): void {
				$contextResolver = PipelineFactory::createContextResolver($projectRoot);
				$edgeIndex = PipelineFactory::createTemplateEdgeIndex($projectRoot);
				$compiler = new LatteCompiler();
				$scanner = new DeclarationScanner();

				$source = FileSystem::read($projectRoot . '/partial.latte');
				$compiled = $compiler->compile($source, 'LatteTpl_partial_test');
				self::assertNotNull($compiled->getPhpSource());
				$declarations = $scanner->scan($source);
				$contexts = $contextResolver->contextsFor('partial.latte');

				self::assertGreaterThan(
					1,
					count($contexts),
					'root-a/root-b must produce distinct contexts for partial.latte',
				);

				$edgeIndexFactsCount = count($edgeIndex->outgoingSites('partial.latte'));
				self::assertSame(1, $edgeIndexFactsCount);

				$stmts = PipelineFactory::create($projectRoot)->process(
					$compiled,
					$declarations,
					$contexts,
					'partial.latte',
				);

				$calls = (new NodeFinder())->find($stmts, static fn (Node $node) => $node instanceof StaticCall
						&& $node->name instanceof Node\Identifier
						&& $node->name->toString() === 'edgeScope');

				self::assertCount(count($contexts), $calls, 'every context clone keeps its own anchor');

				$keys = array_map(function (Node $node): string {
					self::assertInstanceOf(StaticCall::class, $node);

					return $this->stringArg($node, 0);
				}, $calls);

				self::assertStringEndsWith(
					'#' . $contexts[0]->canonicalHash(),
					$keys[0],
					'store key must carry the includer context\'s own canonical hash, not an ordinal',
				);
				foreach ($keys as $key) {
					self::assertStringStartsWith('partial.latte#', $key);
					self::assertStringContainsString('#leaf.latte#', $key);
				}

				self::assertNotSame($keys[0], $keys[1] ?? null, 'distinct contexts must not collide on the same key');
			},
		);
	}

	public function testEachContextCloneAnchorEmbedsItsOwnContextHash(): void
	{
		$this->withProject(
			[
				'partial.latte' => "{varType string \$n}\n\n{include 'leaf.latte', v: \$n}\n",
				'leaf.latte' => "{\$v}\n",
				'root-a.latte' => "{include 'partial.latte', n: 'a'}\n",
				'root-b.latte' => "{include 'partial.latte', n: 'a', extra: 1}\n",
			],
			function (string $projectRoot): void {
				$contexts = $this->resolveContexts($projectRoot, 'partial.latte');
				self::assertGreaterThan(
					1,
					count($contexts),
					'root-a/root-b must produce distinct contexts for partial.latte',
				);

				$stmts = $this->processIncluder($projectRoot, 'partial.latte');

				$seenHashes = [];
				foreach ($contexts as $index => $context) {
					$method = $this->findMethod($stmts, 'latteMain_ctx' . $index);
					self::assertNotNull($method, 'clone for context ' . $index . ' must exist');

					$call = $this->findEdgeScopeCall($method->stmts ?? [], 'leaf.latte');
					self::assertNotNull($call, 'clone ' . $index . ' must carry its own anchor');

					$key = $this->stringArg($call, 0);
					self::assertStringEndsWith(
						'#' . $context->canonicalHash(),
						$key,
						'clone ' . $index . ' anchor must key by ITS OWN context hash, not another clone\'s',
					);

					$seenHashes[] = $context->canonicalHash();
				}

				self::assertNotSame(
					$seenHashes[0],
					$seenHashes[1] ?? null,
					'fixture must exercise two distinct contexts',
				);
			},
		);
	}

	public function testSameFileNoParamsBlockCarriesCallerLocalManifest(): void
	{
		$this->withProject(
			[
				'includer.latte' => "{varType string \$x}\n{var \$local = 1}\n\n"
					. "{block content}{\$x}{/block}\n\n{include #content}\n",
			],
			function (string $projectRoot): void {
				$stmts = $this->processIncluder($projectRoot, 'includer.latte');
				$call = $this->findEdgeScopeCall($stmts, 'content');

				self::assertNotNull($call);

				$key = $this->stringArg($call, 0);
				self::assertStringStartsWith('includer.latte#', $key);
				self::assertStringContainsString('#content#', $key);

				$manifest = $call->getAttribute('latte.edgeManifest');
				self::assertSame(
					['local'],
					$manifest,
					'declared $x is excluded (blockContent inherits it as a header param); the ad-hoc '
					. '{var} local $local is the only caller local blockContent leaves untyped',
				);
				self::assertSame([], $this->argKeys($call), 'bare {include #content} carries no explicit args');

				// Mechanism proof: same-file target found locally -> BlockDispatchEliminator
				// rewrites the dispatch to a direct $this->blockContent(...) call (never renderBlock()).
				$directCalls = (new NodeFinder())->find($stmts, static fn (Node $node) => $node instanceof MethodCall
						&& $node->name instanceof Node\Identifier
						&& $node->name->toString() === 'blockContent');
				self::assertNotCount(0, $directCalls, 'same-file block dispatch must be rewritten to a direct call');
			},
		);
	}

	// A block-body depth-0 {varType} counts as DECLARED for the manifest exactly like an own
	// {define} param does above - $declared is never a header var and blockContent has no own
	// params, so without the body-{varType} exclusion it would sit in the manifest (same shape as
	// $local in the sibling test above); the body {varType} must exclude it while leaving the
	// genuinely-undeclared $free local captured.
	public function testSameFileNoParamsBlockManifestExcludesBodyVarTypeDeclaredName(): void
	{
		$this->withProject(
			[
				'includer.latte' => "{var \$declared = 1}\n{var \$free = 2}\n\n"
					. "{block content}{varType Foo \$declared}{\$declared}{\$free}{/block}\n\n{include #content}\n",
			],
			function (string $projectRoot): void {
				$stmts = $this->processIncluder($projectRoot, 'includer.latte');
				$call = $this->findEdgeScopeCall($stmts, 'content');

				self::assertNotNull($call);

				$manifest = $call->getAttribute('latte.edgeManifest');
				self::assertSame(
					['free'],
					$manifest,
					'body-varType-declared $declared is excluded; the undeclared $free local is still '
					. 'captured (no over-exclusion)',
				);
			},
		);
	}

	public function testImportedBlockCarriesImporterContextVarsExcludingCallerLocals(): void
	{
		$this->withProject(
			[
				'lib.latte' => "{define imported}Imported.{/define}\n",
				'importer.latte' => "{varType string \$x}\n{var \$local = 1}\n\n"
					. "{import 'lib.latte'}\n\n{include #imported}\n",
			],
			function (string $projectRoot): void {
				$stmts = $this->processIncluder($projectRoot, 'importer.latte');
				$call = $this->findEdgeScopeCall($stmts, 'imported');

				self::assertNotNull($call);

				$manifest = $call->getAttribute('latte.edgeManifest');
				self::assertSame(
					['x'],
					$manifest,
					'imported block manifest is the importer\'s own context vars only - the ad-hoc '
					. '{var} local $local never reaches an imported block at runtime (RuntimeParityTest)',
				);
				self::assertSame([], $this->argKeys($call), 'bare {include #imported} carries no explicit args');

				// Mechanism proof: blockImported is not in THIS file's own class, so
				// BlockDispatchEliminator's methodsByName lookup misses and the dispatch stays a
				// renderBlock() call (never rewritten to a direct call).
				$renderBlockCalls = (new NodeFinder())->find(
					$stmts,
					static fn (Node $node) => $node instanceof MethodCall
							&& $node->name instanceof Node\Identifier
							&& $node->name->toString() === 'renderBlock',
				);
				self::assertNotCount(0, $renderBlockCalls, 'imported block dispatch must stay a renderBlock() call');
			},
		);
	}

	// Counterpart of testSameFileNoParamsBlockManifestExcludesBodyVarTypeDeclaredName above for the
	// imported-block path: the block's own body-{varType} is resolved on its DEFINING file (found
	// via TemplateEdgeIndex::reachableBlockOrigins(), the same mechanism
	// IncludeContractChecker::checkBlockDeclaredVars already uses for this exact "which file
	// declares this block name" problem) rather than the importer's file, since an imported block
	// is a separate compiled class this pass never scans locally.
	public function testImportedBlockManifestExcludesBodyVarTypeDeclaredName(): void
	{
		$this->withProject(
			[
				'lib.latte' => "{define imported}\n{varType string \$x}\n{\$x}\n{/define}\n",
				'importer.latte' => "{varType string \$x}\n{varType int \$y}\n\n"
					. "{import 'lib.latte'}\n\n{include #imported}\n",
			],
			function (string $projectRoot): void {
				$stmts = $this->processIncluder($projectRoot, 'importer.latte');
				$call = $this->findEdgeScopeCall($stmts, 'imported');

				self::assertNotNull($call);

				$manifest = $call->getAttribute('latte.edgeManifest');
				self::assertSame(
					['y'],
					$manifest,
					'body-varType-declared $x is excluded; the undeclared $y context var is still '
					. 'captured (no over-exclusion)',
				);
			},
		);
	}

	// A block name reachable through TWO importable origins (here, two {import}s from the same
	// includer) with DIFFERENT body varTypes each - the manifest must exclude the UNION of both
	// origins' declared names, not just one of them (TemplateEdgeIndexTest::
	// testReachableBlockOriginsListsEveryExtenderDeclaringTheSameSlot pins the origins list
	// itself; this pins the manifest CONSUMING it). Excluding only $a or only $b would leave the
	// other wrongly captured; $c belongs to neither origin and must stay in the manifest.
	public function testImportedBlockManifestExcludesUnionOfBothOriginsDeclaredNames(): void
	{
		$this->withProject(
			[
				'lib-a.latte' => "{define shared}\n{varType string \$a}\n{\$a}\n{/define}\n",
				'lib-b.latte' => "{define shared}\n{varType int \$b}\n{\$b}\n{/define}\n",
				'importer.latte' => "{varType string \$a}\n{varType int \$b}\n{varType string \$c}\n\n"
					. "{import 'lib-a.latte'}\n{import 'lib-b.latte'}\n\n{include #shared}\n",
			],
			function (string $projectRoot): void {
				$stmts = $this->processIncluder($projectRoot, 'importer.latte');
				$call = $this->findEdgeScopeCall($stmts, 'shared');

				self::assertNotNull($call);

				$manifest = $call->getAttribute('latte.edgeManifest');
				self::assertSame(
					['c'],
					$manifest,
					'both $a (lib-a) and $b (lib-b) are excluded via the union of both origins\' declared '
					. 'names; only the undeclared $c is still captured',
				);
			},
		);
	}

	public function testSameFileTypedBlockParamProducesNoAnchor(): void
	{
		$this->withProject(
			[
				'includer3.latte' => "{define typed, string \$p}{\$p}{/define}\n\n{include #typed, 'value'}\n",
			],
			function (string $projectRoot): void {
				$stmts = $this->processIncluder($projectRoot, 'includer3.latte');
				$call = $this->findEdgeScopeCall($stmts, 'typed');

				self::assertNull($call, 'a typed own param is declared - per-variable priority means no capture');
			},
		);
	}

	public function testSameFileUntypedBlockParamCapturesArgExpression(): void
	{
		$this->withProject(
			[
				'includer4.latte' => "{varType string \$x}\n\n{define greet, \$name}Hi {\$name}{/define}\n\n"
					. "{include #greet, \$x . '!'}\n",
			],
			function (string $projectRoot): void {
				$stmts = $this->processIncluder($projectRoot, 'includer4.latte');
				$call = $this->findEdgeScopeCall($stmts, 'greet');

				self::assertNotNull($call);
				self::assertSame(
					['name'],
					$this->argKeys($call),
					'the untyped own param $name captures the call\'s arg expression by its own name',
				);

				$manifest = $call->getAttribute('latte.edgeManifest');
				self::assertSame(
					[],
					$manifest,
					'both $x and $name end up covered by the callee\'s own final param set',
				);

				$arg = $call->args[1] ?? null;
				self::assertInstanceOf(Node\Arg::class, $arg);
				self::assertInstanceOf(Array_::class, $arg->value);
				$item = $arg->value->items[0] ?? null;
				self::assertNotNull($item);
				self::assertInstanceOf(
					Concat::class,
					$item->value,
					'the captured value is the real compound arg expression, not a re-derived placeholder',
				);
			},
		);
	}

	public function testExtractPositionalArgsUndoesSpeculativeSymbolPeekForFunctionCallArg(): void
	{
		$this->withProject(
			[
				'includer5.latte' => "{define wrap, \$inner}{\$inner}{/define}\n\n"
					. "{include #wrap, strtoupper('hi')}\n",
			],
			function (string $projectRoot): void {
				$stmts = $this->processIncluder($projectRoot, 'includer5.latte');
				$call = $this->findEdgeScopeCall($stmts, 'wrap');

				self::assertNotNull($call);
				self::assertSame(
					['inner'],
					$this->argKeys($call),
					'the untyped own param $inner captures the call\'s arg expression by its own name',
				);

				$arg = $call->args[1] ?? null;
				self::assertInstanceOf(Node\Arg::class, $arg);
				self::assertInstanceOf(Array_::class, $arg->value);
				$item = $arg->value->items[0] ?? null;
				self::assertNotNull($item);
				self::assertInstanceOf(
					FuncCall::class,
					$item->value,
					'a T_SYMBOL-leading positional arg not followed by "=>"/":" is a bare expression, not a '
					. 'name: prefix - the speculative peek at "strtoupper" must be undone (re-prepended) '
					. 'rather than dropped, or the captured expression degrades to just the parenthesised '
					. 'argument (\'hi\')',
				);
				self::assertInstanceOf(Node\Name::class, $item->value->name);
				self::assertSame('strtoupper', $item->value->name->toString());
			},
		);
	}

	public function testSameFileMixedTypedUntypedBlockParamsZipPositionally(): void
	{
		$this->withProject(
			[
				'includer6.latte' => "{define mix, int \$typed, \$untyped, string \$typedTwo}{\$untyped}{/define}\n\n"
					. "{include #mix, 1, 'A' . 'B', 'z'}\n",
			],
			function (string $projectRoot): void {
				$stmts = $this->processIncluder($projectRoot, 'includer6.latte');
				$call = $this->findEdgeScopeCall($stmts, 'mix');

				self::assertNotNull($call);
				self::assertSame(
					['untyped'],
					$this->argKeys($call),
					'only the middle, untyped own param is captured - both typed neighbors are declared',
				);

				$manifest = $call->getAttribute('latte.edgeManifest');
				self::assertSame([], $manifest, 'no ambient caller locals in this fixture');

				$arg = $call->args[1] ?? null;
				self::assertInstanceOf(Node\Arg::class, $arg);
				self::assertInstanceOf(Array_::class, $arg->value);
				$item = $arg->value->items[0] ?? null;
				self::assertNotNull($item);
				self::assertInstanceOf(
					Concat::class,
					$item->value,
					'the captured value must be the SECOND call arg\'s own expression ("\'A\' . \'B\'"\'s Concat) '
					. '- a literal Int_ (arg 0) or String_ (arg 2) here would prove the positional zip '
					. 'misaligned to a neighboring typed slot',
				);
			},
		);
	}

	// Hardening pin: a file-form site whose line-tagged statement is missing from the method body
	// (the defensive fallback's only trigger - an eliminator folding it away unexpectedly) must
	// get NO anchor at all, never an end-of-body one. An end-of-body capture could observe a
	// manifest var's type AFTER a reassignment below the site's real line - a WRONG type, not
	// merely a wider one - so a miss must degrade the same way the block-dispatch path already
	// does: skip silently. Drives EdgeAnchorInjector::inject() directly with a hand-built
	// $stmts tree (bypassing the real compiler/eliminators) so the line-tagged statement can be
	// made to genuinely vanish, which normal codegen never lets happen.
	public function testFileFormSiteWithNoLineMatchGetsNoAnchorAnywhereNotEvenAtEndOfBody(): void
	{
		$this->withProject(
			[
				'includer.latte' => "{varType string \$user}\n{varType string \$order}\n\n"
					. "{if \$user !== null}\n\t{include 'x.latte', item: \$order->getItem()}\n{/if}\n",
				'x.latte' => "{\$item}\n",
			],
			function (string $projectRoot): void {
				$edgeIndex = PipelineFactory::createTemplateEdgeIndex($projectRoot);
				$site = $edgeIndex->outgoingSites('includer.latte')[0] ?? null;
				self::assertNotNull($site, 'fixture must declare exactly one outgoing site');

				$injector = $this->edgeAnchorInjectorFor($projectRoot);

				// A synthetic latteMain body whose sole statement sits at a line other than the
				// real include site's - simulates the line-tagged statement having vanished.
				$survivor = new Return_(null, ['startLine' => $site->getLatteLine() + 1000]);
				$class = new Class_('LatteTpl_no_line_match_test', [
					'stmts' => [new ClassMethod('latteMain', ['stmts' => [$survivor]])],
				]);

				$injector->inject([$class], 'includer.latte', []);

				$calls = (new NodeFinder())->find([$class], static fn (Node $node) => $node instanceof StaticCall
						&& $node->name instanceof Node\Identifier
						&& $node->name->toString() === 'edgeScope');
				self::assertSame($calls, [], 'a miss must never fall back to an end-of-body capture');

				$method = $this->findMethod([$class], 'latteMain');
				self::assertNotNull($method);
				self::assertSame(
					[$survivor],
					$method->stmts,
					'the original statement list must be completely untouched',
				);
			},
		);
	}

	private function edgeAnchorInjectorFor(string $projectRoot): EdgeAnchorInjector
	{
		$pipeline = PipelineFactory::create($projectRoot);
		$property = new ReflectionProperty(AnalysisPipeline::class, 'edgeAnchorInjector');
		$property->setAccessible(true);

		/** @var EdgeAnchorInjector $injector */
		$injector = $property->getValue($pipeline);

		return $injector;
	}

	/**
	 * @return array<Node\Stmt>
	 */
	private function processIncluder(string $projectRoot, string $relativePath): array
	{
		$edgeIndex = PipelineFactory::createTemplateEdgeIndex($projectRoot);
		// Force universe enumeration so the include target actually resolves before we assert.
		$edgeIndex->outgoingSites($relativePath);

		$contexts = $this->resolveContexts($projectRoot, $relativePath);

		$source = FileSystem::read($projectRoot . '/' . $relativePath);
		$compiled = (new LatteCompiler())->compile($source, 'LatteTpl_edge_anchor_test');
		self::assertNotNull($compiled->getPhpSource(), 'fixture must compile');
		$declarations = (new DeclarationScanner())->scan($source);

		return PipelineFactory::create($projectRoot)->process($compiled, $declarations, $contexts, $relativePath);
	}

	/**
	 * @return list<TemplateContext>
	 */
	private function resolveContexts(string $projectRoot, string $relativePath): array
	{
		return PipelineFactory::createContextResolver($projectRoot)->contextsFor($relativePath);
	}

	/**
	 * @param array<Node\Stmt> $stmts
	 */
	private function findEdgeScopeCall(array $stmts, string $rawTargetFragment): ?StaticCall
	{
		$calls = (new NodeFinder())->find($stmts, static function (Node $node) use ($rawTargetFragment) {
			if (
				!$node instanceof StaticCall
				|| !$node->name instanceof Node\Identifier
				|| $node->name->toString() !== 'edgeScope'
			) {
				return false;
			}

			$firstArg = $node->args[0] ?? null;

			return $firstArg instanceof Node\Arg
				&& $firstArg->value instanceof String_
				&& strpos($firstArg->value->value, '#' . $rawTargetFragment . '#') !== false;
		});

		$first = $calls[0] ?? null;

		return $first instanceof StaticCall ? $first : null;
	}

	/**
	 * @param array<Node\Stmt> $stmts
	 */
	private function findMethod(array $stmts, string $name): ?ClassMethod
	{
		$class = (new NodeFinder())->findFirstInstanceOf($stmts, Node\Stmt\Class_::class);
		if ($class === null) {
			return null;
		}

		foreach ($class->stmts as $stmt) {
			if ($stmt instanceof ClassMethod && $stmt->name->toString() === $name) {
				return $stmt;
			}
		}

		return null;
	}

	private function stringArg(StaticCall $call, int $index): string
	{
		$arg = $call->args[$index] ?? null;
		self::assertInstanceOf(Node\Arg::class, $arg);
		self::assertInstanceOf(String_::class, $arg->value);

		return $arg->value->value;
	}

	/**
	 * @return list<string>
	 */
	private function argKeys(StaticCall $call): array
	{
		$arg = $call->args[1] ?? null;
		self::assertInstanceOf(Node\Arg::class, $arg);
		self::assertInstanceOf(Array_::class, $arg->value);

		$keys = [];
		foreach ($arg->value->items as $item) {
			self::assertInstanceOf(String_::class, $item->key);
			$keys[] = $item->key->value;
		}

		return $keys;
	}

	/**
	 * @param array<string, string> $files
	 * @param callable(string): void $test
	 */
	private function withProject(array $files, callable $test): void
	{
		$projectRoot = sys_get_temp_dir() . '/latte-edge-anchor-test-' . uniqid('', true);
		foreach ($files as $name => $content) {
			FileSystem::write($projectRoot . '/' . $name, $content);
		}

		try {
			$test($projectRoot);
		} finally {
			FileSystem::delete($projectRoot);
		}
	}

}
