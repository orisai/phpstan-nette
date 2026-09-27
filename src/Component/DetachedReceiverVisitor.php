<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Component;

use OriPhpstan\Nette\Component\Attachment\AttachmentWalk;
use OriPhpstan\Nette\Component\Attachment\ParentAccessors;
use PhpParser\Node;
use PhpParser\Node\FunctionLike;
use PhpParser\NodeVisitorAbstract;

/**
 * Tags a method call whose receiver is provably not attached to any parent, so a rule can report it
 * with the receiver's TYPE in hand.
 *
 * The split is forced by what each half can see. Attachment is a per-body fact about a reference,
 * answered by walking the whole body from its first statement - which a rule bound to the call node
 * cannot do, because PHPStan hands it a scope, not the body that produced it. Which accessor throws
 * is a fact about the receiver's class, answered from a scope - which a parse-time visitor does not
 * have. So the visitor answers the first and records it on the node, and the rule answers the second
 * where the scope is.
 *
 * Every function-like is walked separately and from the initial state, closures and arrow functions
 * included. That is not an optimisation: a closure body runs at a time the enclosing walk does not
 * know, so the enclosing state must not cross into it, and the closure's own walk is where a
 * construction and an access inside one still meet.
 *
 * A custom attribute name rather than a node-connecting one, for the reason
 * NullComparisonParentVisitor gives: PHPStan deprecates `phpParser.nodeConnectingAttribute`.
 */
final class DetachedReceiverVisitor extends NodeVisitorAbstract
{

	public const AttributeName = 'appDetachedComponentReceiver';

	public function enterNode(Node $node): ?Node
	{
		if (!$node instanceof FunctionLike) {
			return null;
		}

		$stmts = $node->getStmts();
		if ($stmts === null) {
			return null;
		}

		foreach (AttachmentWalk::detachedReceiverCalls($stmts, ParentAccessors::methodNames()) as $call) {
			$call->setAttribute(self::AttributeName, true);
		}

		return null;
	}

}
