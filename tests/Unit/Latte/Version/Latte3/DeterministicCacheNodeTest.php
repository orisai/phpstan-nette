<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Version\Latte3;

use Latte\Compiler\Nodes\Php\ArrayItemNode;
use Latte\Compiler\Nodes\Php\Expression\ArrayNode;
use Latte\Compiler\Nodes\Php\Scalar\StringNode;
use Latte\Compiler\Nodes\TextNode;
use Latte\Compiler\Position;
use Latte\Compiler\PrintContext;
use LogicException;
use Nette\Bridges\CacheLatte\Nodes\CacheNode;
use OriPhpstan\Nette\Latte\Version\Latte3\DeterministicCacheNode;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function preg_replace;

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

	// nette/caching 3.3+ prints the key first; the 3.1 bridge (Random::generate(), no base64
	// padding) prints it after the storage argument.
	public function testReplacesTheKeyOnBothBridgeShapes(): void
	{
		self::assertSame(
			"if (\$this->global->cache->createCache('latte-analysis-cache-2:1', [\$k, 'expire' => '1 hour'])) /* line 2 */\n"
			. "try {\n\techo 'c';\n\t\$this->global->cache->end() /* line 2 */;\n} catch (\\Throwable \$ʟ_e) {\n"
			. "\t\$this->global->cache->rollback();\n\tthrow \$ʟ_e;\n}\n",
			DeterministicCacheNode::replaceKey(
				"if (\$this->global->cache->createCache('+1rlmHTDFuCBCA==', [\$k, 'expire' => '1 hour'])) /* line 2 */\n"
				. "try {\n\techo 'c';\n\t\$this->global->cache->end() /* line 2 */;\n} catch (\\Throwable \$ʟ_e) {\n"
				. "\t\$this->global->cache->rollback();\n\tthrow \$ʟ_e;\n}\n",
				'latte-analysis-cache-2:1',
			),
		);
		self::assertSame(
			"if (Nette\\Bridges\\CacheLatte\\Nodes\\CacheNode::createCache(\$this->global->cacheStorage, 'latte-analysis-cache-2:1', \$this->global->cacheStack, [\$k, 'expire' => '1 hour'])) /* line 2 */\n"
			. "try {\n\techo 'c';\n\tNette\\Bridges\\CacheLatte\\Nodes\\CacheNode::endCache(\$this->global->cacheStack, [\$k, 'expire' => '1 hour']) /* line 2 */;\n"
			. "} catch (\\Throwable \$ʟ_e) {\n\tNette\\Bridges\\CacheLatte\\Nodes\\CacheNode::rollback(\$this->global->cacheStack);\n\tthrow \$ʟ_e;\n}\n",
			DeterministicCacheNode::replaceKey(
				"if (Nette\\Bridges\\CacheLatte\\Nodes\\CacheNode::createCache(\$this->global->cacheStorage, 'k7x2mq0pza', \$this->global->cacheStack, [\$k, 'expire' => '1 hour'])) /* line 2 */\n"
				. "try {\n\techo 'c';\n\tNette\\Bridges\\CacheLatte\\Nodes\\CacheNode::endCache(\$this->global->cacheStack, [\$k, 'expire' => '1 hour']) /* line 2 */;\n"
				. "} catch (\\Throwable \$ʟ_e) {\n\tNette\\Bridges\\CacheLatte\\Nodes\\CacheNode::rollback(\$this->global->cacheStack);\n\tthrow \$ʟ_e;\n}\n",
				'latte-analysis-cache-2:1',
			),
		);
	}

	public function testAShapeWithoutACreateCacheKeyIsRefused(): void
	{
		$this->expectException(LogicException::class);

		DeterministicCacheNode::replaceKey(
			"if (\$this->global->cache->createCache(\$dynamicKey, [])) try {} finally {}\n",
			'k',
		);
	}

}
