<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte3;

use Latte\Compiler\PrintContext;
use LogicException;
use Nette\Bridges\CacheLatte\Nodes\CacheNode;
use function preg_replace;
use function sprintf;

// CacheNode::print() draws a random key per compile (base64 of ten random bytes); the analysis
// keys a {cache} tag by its position instead, so the generated code is byte-identical across
// compiles and cacheable. The bridge's own print shape is kept whatever the installed version.
final class DeterministicCacheNode extends CacheNode
{

	private const RANDOM_KEY_PATTERN = "~'[A-Za-z0-9+/]{14}=='~";

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
		$key = sprintf(
			"'latte-analysis-cache-%d:%d'",
			$this->position !== null ? $this->position->line : 0,
			$this->position !== null ? $this->position->column : 0,
		);
		$code = preg_replace(self::RANDOM_KEY_PATTERN, $key, parent::print($context), 1, $count);
		if ($code === null || $count !== 1) {
			throw new LogicException('The cache bridge printed no random key to replace.');
		}

		return $code;
	}

}
