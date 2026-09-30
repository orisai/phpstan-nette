<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Version\Latte3;

use Latte\Compiler\Nodes\Php\ArrayItemNode;
use Latte\Compiler\Nodes\Php\Expression\ArrayNode;
use Latte\Compiler\Nodes\Php\Scalar\StringNode;
use Latte\Compiler\Nodes\TextNode;
use Latte\Compiler\Position;
use Latte\Compiler\PrintContext;
use Nette\Bridges\CacheLatte\Nodes\CacheNode;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Version\Latte3\DeterministicCacheNode;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function preg_replace;
use function str_replace;
use function substr_count;

// The installed bridge's own print, with only the key argument of createCache() replaced.
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

		self::assertStringContainsString("createCache('latte-analysis-cache-2:5', ['x'])", $printed);
		self::assertSame($printed, $deterministic->print(new PrintContext()));
		self::assertSame(
			preg_replace(
				"~createCache\('[^']*'~",
				"createCache('latte-analysis-cache-2:5'",
				$vendor->print(new PrintContext()),
				1,
			),
			$printed,
		);
	}

	/**
	 * @dataProvider provideBridgePrint
	 */
	public function testReplacesTheKeyOfTheRealBridgePrint(string $file, string $vendorKey): void
	{
		$print = FileSystem::read(__DIR__ . '/Fixtures/cache-node/' . $file);
		self::assertSame(1, substr_count($print, "'" . $vendorKey . "'"));

		self::assertSame(
			str_replace("'" . $vendorKey . "'", "'latte-analysis-cache-1:1'", $print),
			DeterministicCacheNode::replaceKey($print, 'latte-analysis-cache-1:1'),
		);
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public function provideBridgePrint(): iterable
	{
		yield 'nette/caching 3.1' => ['nette-caching-3.1.4.txt', '6p4a6aw7na'];
		yield 'nette/caching 3.2' => ['nette-caching-3.2.3.txt', 'VoFdv8w0pGMUdA=='];
		yield 'nette/caching 3.3' => ['nette-caching-3.3.1.txt', '2NZ9gpJYaqMceA=='];
	}

	public function testAShapeWithoutACreateCacheKeyKeepsTheVendorPrint(): void
	{
		$print = "if (\$this->global->cache->createCache(\$dynamicKey, [])) try {} finally {}\n";

		self::assertSame($print, DeterministicCacheNode::replaceKey($print, 'k'));
	}

}
