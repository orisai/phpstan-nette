<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte3;

use Latte\Compiler\PrintContext;
use Nette\Bridges\CacheLatte\Nodes\CacheNode;
use function sprintf;

// CacheNode::print() draws a random key per compile; the analysis keys a {cache} tag by its
// position instead, so the generated code is byte-identical across compiles and cacheable.
final class DeterministicCacheNode extends CacheNode
{

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
		return $context->format(
			<<<'XX'
				if ($this->global->cache->createCache(%dump, %node?)) %line
				try {
					%node
					$this->global->cache->end() %line;
				} catch (\Throwable $ʟ_e) {
					$this->global->cache->rollback();
					throw $ʟ_e;
				}


				XX,
			sprintf(
				'latte-analysis-cache-%d:%d',
				$this->position !== null ? $this->position->line : 0,
				$this->position !== null ? $this->position->column : 0,
			),
			$this->args,
			$this->position,
			$this->content,
			$this->endLine,
		);
	}

}
