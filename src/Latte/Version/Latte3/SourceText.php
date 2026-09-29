<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte3;

use Latte\Compiler\Block;
use Latte\Compiler\PrintContext;
use Latte\Compiler\TagParser;
use function substr;
use function trim;

// The raw spelling of a name argument, as the Latte 2 token scanners read it off the source.
final class SourceText
{

	private function __construct()
	{
	}

	/**
	 * @param array{int, int}|null $span
	 */
	public static function slice(string $source, ?array $span): ?string
	{
		return $span === null ? null : trim(substr($source, $span[0], $span[1] - $span[0]));
	}

	// A literal block name as parsed; a dynamic one as written ({block $name} -> '$name'), the way
	// Latte 2 keyed its define-param map by the raw word.
	public static function blockName(Block $block, TagRecord $record, string $source): string
	{
		$literal = NodeReader::blockName($block);
		if ($literal !== null) {
			return $literal;
		}

		$raw = self::slice($source, $record->measureNameExpression(static function (TagParser $parser): void {
			$parser->tryConsumeTokenBeforeUnquotedString('local');
			$parser->stream->tryConsume('#');
		}));

		return $raw ?? $block->name->print(new PrintContext());
	}

}
