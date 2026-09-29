<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte3;

use Latte\CompileException;
use Latte\Compiler\Tag;
use Latte\Compiler\TagParser;
use Latte\Compiler\Token;
use LogicException;

// What TagRecorder saw of one tag while Latte parsed it: the tag itself (name, position, raw
// argument text, n:attribute-ness), every intermediate tag sent into its parser ({else}, {case},
// ...) and whether a closing tag ended it. The tag's own token stream is kept so an expression can
// be re-measured against the template source after the parse.
final class TagRecord
{

	private Tag $tag;

	/** @var list<Tag> */
	private array $intermediateTags = [];

	private ?Tag $closingTag = null;

	public function __construct(Tag $tag)
	{
		$this->tag = $tag;
	}

	public function endTag(Tag $tag): void
	{
		if ($tag->closing) {
			$this->closingTag = $tag;
		} elseif ($tag !== $this->tag) {
			$this->intermediateTags[] = $tag;
		}
	}

	public function getTag(): Tag
	{
		return $this->tag;
	}

	public function getName(): string
	{
		return $this->tag->name;
	}

	public function isAttribute(): bool
	{
		return $this->tag->isNAttribute();
	}

	public function getLine(): int
	{
		return $this->tag->position->line;
	}

	public function getColumn(): int
	{
		return $this->tag->position->column;
	}

	public function getOffset(): int
	{
		return $this->tag->position->offset;
	}

	public function getArgumentsText(): string
	{
		return $this->tag->parser->text;
	}

	public function hasBody(): bool
	{
		return $this->closingTag !== null;
	}

	public function getClosingTag(): ?Tag
	{
		return $this->closingTag;
	}

	/**
	 * @return list<Tag>
	 */
	public function getIntermediateTags(): array
	{
		return $this->intermediateTags;
	}

	// Template offset of the tag's End token: the closing brace, or the end of an attribute value.
	public function getEndOffset(): int
	{
		$stream = $this->tag->parser->stream;
		$save = $stream->getIndex();
		while (!$stream->peek()->isEnd()) {
			$stream->consume();
		}

		$end = $this->offsetOf($stream->peek());
		$stream->seek($save);

		return $end;
	}

	// Replays the tag's own argument grammar up to and including its name expression and returns
	// the expression's [start, end) template offsets, or null when the arguments hold no such
	// expression. $prefix consumes whatever the tag's create() consumes before the name.

	/**
	 * @param callable(TagParser): void $prefix
	 * @return array{int, int}|null
	 */
	public function measureNameExpression(callable $prefix): ?array
	{
		$parser = $this->tag->parser;
		$stream = $parser->stream;
		$save = $stream->getIndex();

		try {
			$stream->seek(0);
			$prefix($parser);
			if ($parser->isEnd()) {
				return null;
			}

			$start = $this->offsetOf($stream->peek());
			$parser->parseUnquotedStringOrExpression();

			return [$start, $this->offsetOf($stream->peek())];
		} catch (CompileException $e) {
			return null;
		} finally {
			$stream->seek($save);
		}
	}

	// Every token the lexer produces is positioned; only synthetic tokens are not, and none reach a
	// tag's own stream.
	private function offsetOf(Token $token): int
	{
		if ($token->position === null) {
			throw new LogicException('A lexer token carries its position.');
		}

		return $token->position->offset;
	}

}
