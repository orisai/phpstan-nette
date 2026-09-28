<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess\Eliminator;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use function count;
use function in_array;

final class EscapingEliminator extends EliminatorVisitor
{

	private const CLASS_NAMES = ['Latte\Runtime\Filters', 'LR\Filters'];

	private const METHOD_NAMES = [
		'escapeHtmlText',
		'escapeHtmlAttr',
		'escapeHtmlComment',
		'escapeXml',
		'escapeCss',
		'escapeICal',
		'safeUrl',
	];

	public function describePattern(): string
	{
		return 'unwrap Latte\Runtime\Filters::escape*/safeUrl($e) -> $e, recursively; escapeJs kept (JSON-encodes any value)';
	}

	/**
	 * @return Node|null
	 */
	public function leaveNode(Node $node)
	{
		if (!$node instanceof StaticCall || !$this->isEscapeCall($node)) {
			return null;
		}

		$arg = $node->args[0];

		return $arg instanceof Node\Arg ? $arg->value : null;
	}

	private function isEscapeCall(StaticCall $node): bool
	{
		if (!$node->class instanceof Node\Name || !in_array($node->class->toString(), self::CLASS_NAMES, true)) {
			return false;
		}

		if (!$node->name instanceof Node\Identifier || !in_array($node->name->toString(), self::METHOD_NAMES, true)) {
			return false;
		}

		return count($node->args) === 1;
	}

}
