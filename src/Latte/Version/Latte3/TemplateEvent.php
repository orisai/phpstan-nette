<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte3;

use Latte\Compiler\Node;
use Latte\Compiler\Tag;
use Latte\Essential\Nodes\ForeachNode;

// One element of the flat, source-ordered stream the node tree is reduced to: the Latte 2 token
// kinds the token-level fact scanners keyed on (an opening or intermediate macro tag, a closing
// tag, an HTML tag begin, a text run), each pointing back at the node and tag record behind it.
final class TemplateEvent
{

	public const TAG = 'tag';

	public const CLOSE = 'close';

	public const ELEMENT = 'element';

	public const TEXT = 'text';

	/** @var self::* */
	public string $kind;

	public int $offset;

	public int $sequence = 0;

	public int $line;

	public int $column;

	public string $name;

	public ?Node $node;

	public ?TagRecord $record;

	public bool $whitespace;

	public ?ForeachNode $foreach;

	public bool $opensBody;

	public bool $closesBody;

	/**
	 * @param self::* $kind
	 */
	private function __construct(
		string $kind,
		int $offset,
		int $line,
		int $column,
		string $name,
		?Node $node,
		?TagRecord $record,
		bool $whitespace,
		?ForeachNode $foreach,
		bool $opensBody,
		bool $closesBody
	)
	{
		$this->kind = $kind;
		$this->offset = $offset;
		$this->line = $line;
		$this->column = $column;
		$this->name = $name;
		$this->node = $node;
		$this->record = $record;
		$this->whitespace = $whitespace;
		$this->foreach = $foreach;
		$this->opensBody = $opensBody;
		$this->closesBody = $closesBody;
	}

	public static function tag(Node $node, TagRecord $record, bool $opensBody): self
	{
		return new self(
			self::TAG,
			$record->getOffset(),
			$record->getLine(),
			$record->getColumn(),
			$record->getName(),
			$node,
			$record,
			false,
			null,
			$opensBody,
			false,
		);
	}

	// {else}, {elseif}, {case}, ...: a tag of its own in the Latte 2 stream, no node of its own here.
	public static function intermediate(Tag $tag): self
	{
		return new self(
			self::TAG,
			$tag->position->offset,
			$tag->position->line,
			$tag->position->column,
			$tag->name,
			null,
			null,
			false,
			null,
			false,
			false,
		);
	}

	public static function close(Tag $tag, bool $closesBody): self
	{
		return new self(
			self::CLOSE,
			$tag->position->offset,
			$tag->position->line,
			$tag->position->column,
			$tag->name,
			null,
			null,
			false,
			null,
			false,
			$closesBody,
		);
	}

	public static function element(string $name, int $offset, int $line, ?ForeachNode $foreach): self
	{
		return new self(self::ELEMENT, $offset, $line, 0, $name, null, null, false, $foreach, false, false);
	}

	public static function text(int $offset, int $line, bool $whitespace): self
	{
		return new self(self::TEXT, $offset, $line, 0, '', null, null, $whitespace, null, false, false);
	}

}
