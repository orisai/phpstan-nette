<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte3;

use Closure;
use Generator;
use Latte\Compiler\Nodes\AreaNode;
use Latte\Compiler\Nodes\FragmentNode;
use Latte\Compiler\Tag;

final class PassthroughTagParser
{

	private const INTERMEDIATE_TAGS = ['else', 'elseif', 'elseifset', 'case'];

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

	// An unknown pair may be a custom conditional: asking for the intermediate tags lets Latte route
	// an {else} to it only while it is the innermost open tag; the branches compile in sequence.

	/**
	 * @return Closure(Tag): Generator<int, list<string>, array{AreaNode, Tag|null}, PassthroughNode>
	 */
	public static function paired(): Closure
	{
		return static function (Tag $tag): Generator {
			self::consumeArguments($tag);
			$node = new PassthroughNode();
			$fragment = new FragmentNode();
			do {
				[$content, $nextTag] = yield self::INTERMEDIATE_TAGS;
				$fragment->append($content);
				// A void {name /} is sent its own tag, an n:attribute null, a pair its closing tag.
				$intermediate = $nextTag !== null && $nextTag !== $tag && !$nextTag->closing;
				if ($intermediate) {
					self::consumeArguments($nextTag);
				}
			} while ($intermediate);

			$node->content = $fragment;

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
