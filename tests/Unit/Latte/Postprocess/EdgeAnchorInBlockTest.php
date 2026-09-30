<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Postprocess;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Compile\TemplateClassName;
use OriPhpstan\Nette\Latte\Includes\TemplateContext;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\PipelineFactory;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;
use function sort;
use function strpos;
use function sys_get_temp_dir;
use function uniqid;

// A file include inside a block body has its statement in the block's own method, not in a
// latteMain clone: the edge anchor sits right before that statement there, on every Latte line.
final class EdgeAnchorInBlockTest extends BaseTestCase
{

	public function testFileIncludeInsideBlockIsAnchoredBeforeItsOwnStatement(): void
	{
		$projectRoot = $this->project("{\$user}\n");

		try {
			$contexts = PipelineFactory::createContextResolver($projectRoot)->contextsFor('includer.latte');
			[$class, $anchors] = $this->processWith($projectRoot, $contexts);

			self::assertCount(1, $anchors);
			[$methodName, $anchor] = $anchors[0];
			self::assertSame('blockContent', $methodName);
			$key = $anchor->args[0]->value ?? null;
			self::assertInstanceOf(String_::class, $key);
			self::assertSame(0, strpos($key->value, 'includer.latte#4#x.latte#'));

			$body = $this->blockContent($class)->stmts ?? [];
			self::assertInstanceOf(Expression::class, $body[0]);
			self::assertSame($anchor, $body[0]->expr);
			self::assertStringContainsString(
				"createTemplate('x.latte'",
				(new Standard())->prettyPrint([$body[1]]),
			);
		} finally {
			FileSystem::delete($projectRoot);
		}
	}

	// Two contexts agreeing on every name the anchor records ($user) while differing on a name the
	// target declares itself ($mode, never captured): the shared block method gets one anchor per
	// context, each keyed by its own context, right before the include statement.
	public function testAgreeingContextsEachAnchorInsideTheBlockMethod(): void
	{
		$projectRoot = $this->project("{varType string \$mode}\n{\$user}{\$mode}\n");

		try {
			$contexts = [
				TemplateContext::root(['user' => 'string', 'mode' => 'int']),
				TemplateContext::root(['user' => 'string', 'mode' => 'string']),
			];
			[$class, $anchors] = $this->processWith($projectRoot, $contexts);

			self::assertCount(2, $anchors);
			$keys = [];
			foreach ($anchors as [$methodName, $anchor]) {
				self::assertSame('blockContent', $methodName);
				$key = $anchor->args[0]->value ?? null;
				self::assertInstanceOf(String_::class, $key);
				$keys[] = $key->value;
			}

			sort($keys);
			$expected = [];
			foreach ($contexts as $context) {
				$expected[] = 'includer.latte#4#x.latte#' . $context->canonicalHash();
			}

			sort($expected);
			self::assertSame($expected, $keys);

			$body = $this->blockContent($class)->stmts ?? [];
			self::assertInstanceOf(Expression::class, $body[0]);
			self::assertInstanceOf(Expression::class, $body[1]);
			self::assertStringContainsString(
				"createTemplate('x.latte'",
				(new Standard())->prettyPrint([$body[2]]),
			);
		} finally {
			FileSystem::delete($projectRoot);
		}
	}

	// The block method's $user is the union of both contexts (mixed): a capture there would loosen
	// the type each edge already has, so no anchor is emitted anywhere.
	public function testDisagreeingContextsGetNoBlockAnchor(): void
	{
		$projectRoot = $this->project("{\$user}\n");

		try {
			$contexts = [
				TemplateContext::root(['user' => 'string']),
				TemplateContext::root(['user' => 'int']),
			];
			[, $anchors] = $this->processWith($projectRoot, $contexts);

			self::assertSame([], $anchors);
		} finally {
			FileSystem::delete($projectRoot);
		}
	}

	private function project(string $target): string
	{
		$projectRoot = sys_get_temp_dir() . '/latte-edge-anchor-block-' . uniqid('', true);
		FileSystem::write(
			$projectRoot . '/includer.latte',
			"{varType string \$user}\n{block title}T{/block}\n{block content}\n\t{include 'x.latte'}\n{/block}\n",
		);
		FileSystem::write($projectRoot . '/x.latte', $target);

		return $projectRoot;
	}

	/**
	 * @param list<TemplateContext> $contexts
	 * @return array{Class_, list<array{string, StaticCall}>}
	 */
	private function processWith(string $projectRoot, array $contexts): array
	{
		$edgeIndex = PipelineFactory::createTemplateEdgeIndex($projectRoot);
		$edgeIndex->outgoingSites('includer.latte');
		$source = FileSystem::read($projectRoot . '/includer.latte');
		$compiled = TestAdapter::create()->compile(
			$source,
			TemplateClassName::forPath('includer.latte'),
			'includer.latte',
		);
		$stmts = PipelineFactory::create($projectRoot)->process(
			$compiled->getResult(),
			$compiled->getFacts()->getDeclarations(),
			$contexts,
			'includer.latte',
		);

		$class = (new NodeFinder())->findFirstInstanceOf($stmts, Class_::class);
		self::assertInstanceOf(Class_::class, $class);

		$anchors = [];
		foreach ($class->stmts as $method) {
			if (!$method instanceof ClassMethod) {
				continue;
			}

			foreach ((new NodeFinder())->findInstanceOf($method, StaticCall::class) as $call) {
				if ($call->name instanceof Node\Identifier && $call->name->toString() === 'edgeScope') {
					$anchors[] = [$method->name->toString(), $call];
				}
			}
		}

		return [$class, $anchors];
	}

	private function blockContent(Class_ $class): ClassMethod
	{
		foreach ($class->stmts as $method) {
			if ($method instanceof ClassMethod && $method->name->toString() === 'blockContent') {
				return $method;
			}
		}

		self::fail('blockContent method missing');
	}

}
