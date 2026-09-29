<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte3;

use Latte\Compiler\PrintContext;
use LogicException;
use Nette\Bridges\CacheLatte\Nodes\CacheNode;
use function preg_replace;
use function sprintf;

// CacheNode::print() draws a random key per compile; the analysis keys a {cache} tag by its
// position instead, so the generated code is byte-identical across compiles and cacheable. The
// bridge's own print shape is kept whatever the installed version: the key is the first quoted
// argument of its createCache() call (nette/caching >= 3.3: `$this->global->cache->createCache(key,
// ...)`; the 3.1 bridge: `CacheNode::createCache($this->global->cacheStorage, key, ...)`).
final class DeterministicCacheNode extends CacheNode
{

	private const KEY_ARGUMENT_PATTERN = "~(createCache\\((?:\\\$this->global->cacheStorage,\\s*)?)'[^']*'~";

	public static function of(CacheNode $node): self
	{
		$deterministic = new self();
		$deterministic->args = $node->args;
		$deterministic->content = $node->content;
		$deterministic->endLine = $node->endLine;
		$deterministic->position = $node->position;

		return $deterministic;
	}

	public function print(PrintContext $context): string
	{
		return self::replaceKey(
			parent::print($context),
			sprintf(
				'latte-analysis-cache-%d:%d',
				$this->position !== null ? $this->position->line : 0,
				$this->position !== null ? $this->position->column : 0,
			),
		);
	}

	public static function replaceKey(string $code, string $key): string
	{
		$replaced = preg_replace(self::KEY_ARGUMENT_PATTERN, "\$1'" . $key . "'", $code, 1, $count);
		if ($replaced === null || $count !== 1) {
			throw new LogicException('The cache bridge printed no createCache() key to replace.');
		}

		return $replaced;
	}

}
