<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte3;

use Latte\Compiler\Node;
use Latte\Compiler\Nodes\Php\ExpressionNode;
use Latte\Compiler\Nodes\Php\ModifierNode;
use Latte\Compiler\PrintContext;
use Latte\Compiler\TagParser;
use Latte\Essential\Nodes\EmbedNode;
use Latte\Essential\Nodes\ExtendsNode;
use Latte\Essential\Nodes\ImportNode;
use Latte\Essential\Nodes\IncludeBlockNode;
use Latte\Essential\Nodes\IncludeFileNode;
use Latte\Sandbox\Nodes\SandboxNode;
use OriPhpstan\Nette\Latte\Includes\IncludePath;
use OriPhpstan\Nette\Latte\Includes\IncludeTarget;
use OriPhpstan\Nette\Latte\Includes\TemplateFacts;
use function in_array;
use function ltrim;
use function preg_replace;
use function strlen;
use function strpos;
use function substr;
use function trim;

// One include-family tag -> the IncludeTarget Latte 2's token extractor produced for it: the target
// word as written (dequoted, '#'-stripped for a block), classified by the node Latte 3 chose, and
// the argument text after it with the escape-filter suffix left out.
final class IncludeSiteReader
{

	private const LAYOUT_TAGS = ['extends', 'layout'];

	public static function isIncludeFamily(Node $node): bool
	{
		return $node instanceof IncludeFileNode
			|| $node instanceof IncludeBlockNode
			|| $node instanceof EmbedNode
			|| $node instanceof ExtendsNode
			|| $node instanceof ImportNode
			|| $node instanceof SandboxNode;
	}

	/**
	 * @return TemplateFacts::LAYOUT_MODE_*
	 */
	public static function layoutMode(TagRecord $record): string
	{
		$args = trim($record->getArgumentsText());
		if ($args === TemplateFacts::LAYOUT_MODE_NONE) {
			return TemplateFacts::LAYOUT_MODE_NONE;
		}

		if ($args === TemplateFacts::LAYOUT_MODE_AUTO) {
			return TemplateFacts::LAYOUT_MODE_AUTO;
		}

		return TemplateFacts::LAYOUT_MODE_DECLARED;
	}

	public static function read(
		Node $node,
		TagRecord $record,
		string $source,
		string $projectRelativePath
	): ?IncludeTarget
	{
		$tag = $record->getName();
		if (
			in_array($tag, self::LAYOUT_TAGS, true)
			&& in_array(trim($record->getArgumentsText()), ['none', 'auto'], true)
		) {
			return null;
		}

		$span = $record->measureNameExpression(self::prefixFor($node));
		$word = SourceText::slice($source, $span) ?? self::printedName($node);
		if ($word === null) {
			return null;
		}

		$argsSource = $span === null ? '' : self::argsSource($node, $record, $source, $span[1]);

		if ($node instanceof IncludeFileNode && $node->mode === 'includeblock') {
			$tag = 'includeblock';
		}

		if (strpos($word, '$') !== false || strpos($word, '(') !== false) {
			return new IncludeTarget($tag, IncludeTarget::KIND_DYNAMIC, $word, null, $argsSource, $record->getLine());
		}

		$isBlockMode = $node instanceof IncludeBlockNode || ($node instanceof EmbedNode && $node->mode === 'block');
		if ($isBlockMode) {
			return new IncludeTarget(
				$tag,
				IncludeTarget::KIND_STATIC_BLOCK,
				ltrim(self::dequote($word), '#'),
				null,
				$argsSource,
				$record->getLine(),
			);
		}

		$literal = self::dequote($word);

		return new IncludeTarget(
			$tag,
			IncludeTarget::KIND_STATIC_FILE,
			$literal,
			IncludePath::normalize($projectRelativePath, $literal),
			$argsSource,
			$record->getLine(),
		);
	}

	// What each node's create() consumes before its name expression.

	/**
	 * @return callable(TagParser): void
	 */
	private static function prefixFor(Node $node): callable
	{
		if ($node instanceof IncludeBlockNode) {
			return static function (TagParser $parser): void {
				if ($parser->tryConsumeTokenBeforeUnquotedString('block') === null) {
					$parser->stream->tryConsume('#');
				}
			};
		}

		if ($node instanceof IncludeFileNode) {
			return static function (TagParser $parser): void {
				$parser->tryConsumeTokenBeforeUnquotedString('file');
			};
		}

		if ($node instanceof EmbedNode) {
			return static function (TagParser $parser): void {
				$parser->tryConsumeTokenBeforeUnquotedString('block', 'file');
			};
		}

		return static function (TagParser $parser): void {
		};
	}

	private static function printedName(Node $node): ?string
	{
		$expression = self::nameExpression($node);

		return $expression === null ? null : $expression->print(new PrintContext());
	}

	private static function nameExpression(Node $node): ?ExpressionNode
	{
		if ($node instanceof IncludeFileNode || $node instanceof ImportNode || $node instanceof SandboxNode) {
			return $node->file;
		}

		if ($node instanceof IncludeBlockNode || $node instanceof EmbedNode) {
			return $node->name;
		}

		if ($node instanceof ExtendsNode) {
			return $node->extends;
		}

		return null;
	}

	// Everything after the name up to the filter suffix: Latte 2's fetchWord() consumed the
	// separating comma and joined the rest. `with blocks` is Latte 3 syntax with no Latte 2 spelling.
	private static function argsSource(Node $node, TagRecord $record, string $source, int $nameEnd): string
	{
		$end = $record->getEndOffset();
		$modifier = $node instanceof IncludeFileNode || $node instanceof IncludeBlockNode ? $node->modifier : null;
		if ($modifier instanceof ModifierNode && $modifier->position !== null) {
			$end = $modifier->position->offset;
		}

		$args = trim(substr($source, $nameEnd, $end - $nameEnd));
		if ($node instanceof IncludeFileNode && $node->mode === 'includeblock') {
			$args = trim((string) preg_replace('~^with\s+blocks~', '', $args));
		}

		if ($args !== '' && $args[0] === ',') {
			$args = trim(substr($args, 1));
		}

		return $args;
	}

	private static function dequote(string $value): string
	{
		$length = strlen($value);
		if ($length >= 2 && ($value[0] === "'" || $value[0] === '"') && $value[$length - 1] === $value[0]) {
			return substr($value, 1, -1);
		}

		return $value;
	}

}
