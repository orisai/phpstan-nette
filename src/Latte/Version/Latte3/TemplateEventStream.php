<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte3;

use Latte\Compiler\Node;
use Latte\Compiler\Nodes\FragmentNode;
use Latte\Compiler\Nodes\Html\BogusTagNode;
use Latte\Compiler\Nodes\Html\CommentNode;
use Latte\Compiler\Nodes\Html\ElementNode;
use Latte\Compiler\Nodes\TemplateNode;
use Latte\Compiler\Nodes\TextNode;
use Latte\Essential\Nodes\ForeachNode;
use OriPhpstan\Nette\Latte\Includes\MacroPairing;
use function count;
use function in_array;
use function max;
use function trim;
use function usort;

// Reduces the pre-pass node tree to the flat token stream the Latte 2 fact scanners walked: fed by
// one NodeTraverser pass (enter/leave), ordered by source offset afterwards because intermediate
// and closing tags are only known on the node they belong to.
//
// n:attribute-derived nodes are transparent - Latte 2 tokenised them as HTML attributes, never as
// macro tags, so they open no body and count for no line macro; the element they decorate carries
// their one token-level fact (an n:foreach binding). Custom passthrough bodies are transparent for
// depth too, exactly as Latte 2's fixed pair-tag list made them.
final class TemplateEventStream
{

	private TagRecorder $recorder;

	/** @var list<TemplateEvent> */
	private array $events = [];

	private int $cursor = 0;

	public function __construct(TagRecorder $recorder)
	{
		$this->recorder = $recorder;
	}

	public function enter(Node $node): void
	{
		if ($node instanceof TemplateNode || $node instanceof FragmentNode) {
			return;
		}

		$record = $this->recorder->recordFor($node);
		if ($record !== null) {
			if ($record->isAttribute()) {
				return;
			}

			$this->emit(TemplateEvent::tag(
				$node,
				$record,
				$record->hasBody() && in_array($record->getName(), MacroPairing::PAIR_TAGS, true),
			));
			foreach ($record->getIntermediateTags() as $tag) {
				$this->emit(TemplateEvent::intermediate($tag));
			}

			return;
		}

		if ($node instanceof ElementNode) {
			$this->emit(TemplateEvent::element(
				$node->name,
				$this->offsetOf($node),
				$this->lineOf($node),
				$this->foreachOf($node),
			));

			return;
		}

		// <!-- -->, <!DOCTYPE> and <? were nameless HTML_TAG_BEGIN tokens to Latte 2.
		if ($node instanceof CommentNode || $node instanceof BogusTagNode) {
			$this->emit(TemplateEvent::element('', $this->offsetOf($node), $this->lineOf($node), null));

			return;
		}

		if ($node instanceof TextNode && $node->content !== '') {
			$this->emit(TemplateEvent::text($this->offsetOf($node), $this->lineOf($node), trim($node->content) === ''));
		}
	}

	public function leave(Node $node): void
	{
		$record = $this->recorder->recordFor($node);
		if ($record !== null) {
			$closing = $record->getClosingTag();
			if (!$record->isAttribute() && $closing !== null) {
				$this->emit(TemplateEvent::close(
					$closing,
					$closing->name === '' || in_array($closing->name, MacroPairing::PAIR_TAGS, true),
				));
			}

			return;
		}

		// An element with content has an end tag - a nameless-for-anchors HTML_TAG_BEGIN in Latte 2.
		if ($node instanceof ElementNode && $node->content !== null) {
			$this->emit(TemplateEvent::element($node->name, $this->cursor, $this->lineOf($node), null));
		}
	}

	/**
	 * @return list<TemplateEvent>
	 */
	public function events(): array
	{
		$events = $this->events;
		usort(
			$events,
			static fn (TemplateEvent $a, TemplateEvent $b): int => [$a->offset, $a->sequence] <=> [$b->offset, $b->sequence],
		);

		return $events;
	}

	private function emit(TemplateEvent $event): void
	{
		$event->sequence = count($this->events);
		$this->cursor = max($this->cursor, $event->offset);
		$this->events[] = $event;
	}

	private function foreachOf(ElementNode $element): ?ForeachNode
	{
		foreach ($element->nAttributes as $attribute => $tag) {
			if ($attribute !== 'foreach' && $attribute !== 'inner-foreach') {
				continue;
			}

			$node = $this->recorder->nodeFor($tag);
			if ($node instanceof ForeachNode) {
				return $node;
			}
		}

		return null;
	}

	private function offsetOf(Node $node): int
	{
		return $node->position !== null ? $node->position->offset : $this->cursor;
	}

	private function lineOf(Node $node): int
	{
		return $node->position !== null ? $node->position->line : 0;
	}

}
