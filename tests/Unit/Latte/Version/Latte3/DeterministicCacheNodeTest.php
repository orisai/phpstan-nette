<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Version\Latte3;

use Latte\Compiler\Nodes\Php\ArrayItemNode;
use Latte\Compiler\Nodes\Php\Expression\ArrayNode;
use Latte\Compiler\Nodes\Php\Scalar\StringNode;
use Latte\Compiler\Nodes\TextNode;
use Latte\Compiler\Position;
use Latte\Compiler\PrintContext;
use Nette\Bridges\CacheLatte\Nodes\CacheNode;
use OriPhpstan\Nette\Latte\Version\Latte3\DeterministicCacheNode;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function preg_replace;

// The installed bridge's own print, with only its random key replaced.
/**
 * @group latte3
 */
final class DeterministicCacheNodeTest extends BaseTestCase
{

	public function testPrintsLikeTheInstalledBridgeModuloTheKey(): void
	{
		$vendor = new CacheNode();
		$vendor->args = new ArrayNode([new ArrayItemNode(new StringNode('x'))]);
		$vendor->content = new TextNode('c');
		$vendor->endLine = new Position(3, 4);
		$vendor->position = new Position(2, 5);

		$deterministic = DeterministicCacheNode::of($vendor);
		$printed = $deterministic->print(new PrintContext());

		self::assertStringContainsString("'latte-analysis-cache-2:5'", $printed);
		self::assertSame($printed, $deterministic->print(new PrintContext()));
		self::assertSame(
			preg_replace(
				"~'[A-Za-z0-9+/]{14}=='~",
				"'latte-analysis-cache-2:5'",
				$vendor->print(new PrintContext()),
				1,
			),
			$printed,
		);
	}

}
