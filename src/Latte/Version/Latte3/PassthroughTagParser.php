<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte3;

use Closure;
use Generator;
use Latte\Compiler\Nodes\AreaNode;
use Latte\Compiler\Tag;

final class PassthroughTagParser
{

	private function __construct()
	{
	}

	/**
	 * @return Closure(Tag): PassthroughNode
	 */
	public static function unpaired(): Closure
	{
		return static function (Tag $tag): PassthroughNode {
			self::consumeArguments($tag);

			return new PassthroughNode();
		};
	}

	/**
	 * @return Closure(Tag): Generator<int, null, array{AreaNode, Tag|null}, PassthroughNode>
	 */
	public static function paired(): Closure
	{
		return static function (Tag $tag): Generator {
			self::consumeArguments($tag);
			$node = new PassthroughNode();
			[$node->content] = yield;

			return $node;
		};
	}

	// TemplateParser::ensureIsConsumed() rejects a parser that leaves arguments unread.
	private static function consumeArguments(Tag $tag): void
	{
		while (!$tag->parser->isEnd()) {
			$tag->parser->stream->consume();
		}
	}

}
