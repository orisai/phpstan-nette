<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Postprocess;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Compile\TemplateClassName;
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
use function strpos;
use function sys_get_temp_dir;
use function uniqid;

// A file include inside a block body has its statement in the block's own method, not in a
// latteMain clone: the edge anchor sits right before that statement there, on every Latte line.
final class EdgeAnchorInBlockTest extends BaseTestCase
{

	public function testFileIncludeInsideBlockIsAnchoredBeforeItsOwnStatement(): void
	{
		$projectRoot = sys_get_temp_dir() . '/latte-edge-anchor-block-' . uniqid('', true);
		FileSystem::write(
			$projectRoot . '/includer.latte',
			"{varType string \$user}\n{block title}T{/block}\n{block content}\n\t{include 'x.latte'}\n{/block}\n",
		);
		FileSystem::write($projectRoot . '/x.latte', "{\$user}\n");

		try {
			$edgeIndex = PipelineFactory::createTemplateEdgeIndex($projectRoot);
			$edgeIndex->outgoingSites('includer.latte');
			$contexts = PipelineFactory::createContextResolver($projectRoot)->contextsFor('includer.latte');
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

			self::assertCount(1, $anchors);
			[$methodName, $anchor] = $anchors[0];
			self::assertSame('blockContent', $methodName);
			$key = $anchor->args[0]->value ?? null;
			self::assertInstanceOf(String_::class, $key);
			self::assertSame(0, strpos($key->value, 'includer.latte#4#x.latte#'));

			$block = null;
			foreach ($class->stmts as $method) {
				if ($method instanceof ClassMethod && $method->name->toString() === 'blockContent') {
					$block = $method;
				}
			}

			self::assertNotNull($block);
			$body = $block->stmts ?? [];
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

}
