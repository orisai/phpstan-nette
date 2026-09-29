<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use function count;
use function explode;
use function preg_match;
use function strncmp;
use function trim;

final class LineMapper
{

	private string $markerPattern;

	public function __construct(string $markerPattern)
	{
		$this->markerPattern = $markerPattern;
	}

	/**
	 * @return array<int, int>
	 */
	public function buildMap(string $phpSource): array
	{
		$map = [];
		$marked = [];
		$lines = explode("\n", $phpSource);
		$current = 1;
		foreach ($lines as $index => $lineText) {
			$generatedLine = $index + 1;
			if (preg_match($this->markerPattern, $lineText, $m) === 1) {
				$current = (int) $m['line'];
				$marked[$generatedLine] = true;
			}

			$map[$generatedLine] = $current;
		}

		$next = null;
		for ($generatedLine = count($lines); $generatedLine >= 1; $generatedLine--) {
			if (isset($marked[$generatedLine])) {
				$next = $map[$generatedLine];

				continue;
			}

			// Latte marks a macro's last statement; unmarked echoes are text or marker-less macros of the previous line.
			$trimmed = trim($lines[$generatedLine - 1]);
			if (
				$trimmed === ''
				|| $trimmed[0] === '}'
				|| strncmp($trimmed, 'echo ', 5) === 0
				|| $next === null
				|| $next < $map[$generatedLine]
			) {
				continue;
			}

			$map[$generatedLine] = $next;
		}

		return $map;
	}

	/**
	 * @param array<Node> $nodes
	 * @param array<int, int> $map
	 */
	public function remap(array $nodes, array $map): void
	{
		$traverser = new NodeTraverser();
		$traverser->addVisitor(new class ($map) extends NodeVisitorAbstract {

			/** @var array<int, int> */
			private array $map;

			/**
			 * @param array<int, int> $map
			 */
			public function __construct(array $map)
			{
				$this->map = $map;
			}

			public function enterNode(Node $node): ?Node
			{
				$latteLine = $this->map[$node->getStartLine()] ?? 1;
				$node->setAttribute('startLine', $latteLine);
				$node->setAttribute('endLine', $this->map[$node->getEndLine()] ?? $latteLine);

				return null;
			}

		});
		$traverser->traverse($nodes);
	}

}
