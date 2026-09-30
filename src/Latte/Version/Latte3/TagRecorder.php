<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte3;

use Closure;
use Generator;
use Latte\Compiler\Node;
use Latte\Compiler\Nodes\AreaNode;
use Latte\Compiler\Tag;
use Latte\Compiler\TemplateParser;
use Latte\Extension;
use LogicException;
use ReflectionFunction;
use SplObjectStorage;
use stdClass;
use function is_array;
use function is_callable;

// Wraps every tag parser the engine registers so the node each one returns is paired with the Tag
// it was parsed from. Latte 3 nodes keep no tag name, no argument text and no trace of the
// intermediate/closing tags that closed them; the Latte 2 token facts need all three.
final class TagRecorder
{

	/** @var SplObjectStorage<Node, TagRecord> */
	private SplObjectStorage $records;

	/** @var SplObjectStorage<Tag, Node> */
	private SplObjectStorage $nodesByTag;

	private ?int $lastTagLine = null;

	public function __construct()
	{
		$this->records = new SplObjectStorage();
		$this->nodesByTag = new SplObjectStorage();
	}

	/**
	 * @param array<string, (callable(Tag, TemplateParser): (Generator<int, list<string>|null, array{AreaNode, Tag|null}, Node|null>|Node|void))|stdClass> $parsers
	 * @return array<string, (callable(Tag, TemplateParser): (Generator<int, list<string>|null, array{AreaNode, Tag|null}, Node|null>|Node|void))|stdClass>
	 */
	public function wrap(array $parsers): array
	{
		$wrapped = [];
		foreach ($parsers as $name => $info) {
			$subject = $info instanceof stdClass ? $info->subject : $info;
			if (!is_callable($subject)) {
				throw new LogicException("Tag parser for {$name} is not callable.");
			}

			$closure = $this->isGenerator($subject) ? $this->wrapGenerator($subject) : $this->wrapPlain($subject);
			$wrapped[$name] = $info instanceof stdClass
				? Extension::order($closure, $info->before, $info->after)
				: $closure;
		}

		return $wrapped;
	}

	public function lastTagLine(): ?int
	{
		return $this->lastTagLine;
	}

	public function recordFor(Node $node): ?TagRecord
	{
		return $this->records->contains($node) ? $this->records[$node] : null;
	}

	public function nodeFor(Tag $tag): ?Node
	{
		return $this->nodesByTag->contains($tag) ? $this->nodesByTag[$tag] : null;
	}

	/**
	 * @param callable(Tag, TemplateParser): (Generator<int, list<string>|null, array{AreaNode, Tag|null}, Node|null>|Node|void) $subject
	 */
	private function isGenerator(callable $subject): bool
	{
		return (new ReflectionFunction(Closure::fromCallable($subject)))->isGenerator();
	}

	// Delegates by hand rather than with `yield from`: TemplateParser sends [content, endTag] for
	// every intermediate and closing tag, and only the sent values reveal them.

	/**
	 * @param callable(Tag, TemplateParser): (Generator<int, list<string>|null, array{AreaNode, Tag|null}, Node|null>|Node|void) $inner
	 * @return Closure(Tag, TemplateParser): Generator<int, list<string>|null, array{AreaNode, Tag|null}, Node|null>
	 */
	private function wrapGenerator(callable $inner): Closure
	{
		return function (Tag $tag, TemplateParser $parser) use ($inner): Generator {
			$this->enter($tag);
			$generator = $inner($tag, $parser);
			if (!$generator instanceof Generator) {
				throw new LogicException("Tag parser for {$tag->name} was expected to return a Generator.");
			}

			$record = new TagRecord($tag);
			while ($generator->valid()) {
				$sent = yield $generator->current();
				if (is_array($sent) && ($sent[1] ?? null) instanceof Tag) {
					$record->endTag($sent[1]);
				}

				$generator->send($sent);
			}

			$node = $generator->getReturn();
			if ($node instanceof Node) {
				$this->attach($node, $tag, $record);
			}

			return $node;
		};
	}

	// An attribute-only parser (n:tag, n:href, ...) may rewrite its element and return nothing.

	/**
	 * @param callable(Tag, TemplateParser): (Generator<int, list<string>|null, array{AreaNode, Tag|null}, Node|null>|Node|void) $inner
	 * @return Closure(Tag, TemplateParser): (Node|null)
	 */
	private function wrapPlain(callable $inner): Closure
	{
		return function (Tag $tag, TemplateParser $parser) use ($inner): ?Node {
			$this->enter($tag);
			$node = $inner($tag, $parser);
			if (!$node instanceof Node) {
				return null;
			}

			$this->attach($node, $tag, new TagRecord($tag));

			return $node;
		};
	}

	private function enter(Tag $tag): void
	{
		$this->lastTagLine = $tag->position->line;
	}

	private function attach(Node $node, Tag $tag, TagRecord $record): void
	{
		$this->records[$node] = $record;
		$this->nodesByTag[$tag] = $node;
	}

}
