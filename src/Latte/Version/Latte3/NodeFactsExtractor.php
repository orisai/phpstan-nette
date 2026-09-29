<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte3;

use Latte\Compiler\Node;
use Latte\Compiler\NodeTraverser;
use OriPhpstan\Nette\Latte\Includes\TemplateFacts;
use OriPhpstan\Nette\Latte\Version\ExtractedFacts;

// Every template fact of one parse from one traversal of the PRE-PASS node tree: the traversal feeds
// the flat event stream (declarations and include facts) and the form-site collector at once.
final class NodeFactsExtractor
{

	public function extract(ParsedTemplate $parsed, string $source, string $relativePath): ExtractedFacts
	{
		$node = $parsed->getNode();
		$recorder = $parsed->getRecorder();
		if ($node === null || $recorder === null) {
			return ExtractedFacts::eager(
				EventDeclarationScanner::empty(),
				new TemplateFacts([], [], [], [], [], [], [], []),
				[],
			);
		}

		$stream = new TemplateEventStream($recorder);
		$forms = new NodeFormSiteCollector($recorder);
		(new NodeTraverser())->traverse(
			$node,
			static function (Node $child) use ($stream, $forms): void {
				$stream->enter($child);
				$forms->enter($child);
			},
			static function (Node $child) use ($stream, $forms): void {
				$stream->leave($child);
				$forms->leave($child);
			},
		);
		$events = $stream->events();

		return ExtractedFacts::eager(
			(new EventDeclarationScanner())->scan($events, $parsed->getDeclarations(), $source),
			(new EventFactExtractor())->extract($events, $source, $relativePath),
			$forms->sites(),
		);
	}

}
