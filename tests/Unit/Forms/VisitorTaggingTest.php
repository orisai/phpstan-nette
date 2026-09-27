<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms;

use OriPhpstan\Nette\Forms\Graph\ComponentAffectingNodeVisitor;
use OriPhpstan\Nette\Forms\Graph\TaggedNode;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function usort;

final class VisitorTaggingTest extends BaseTestCase
{

	/** @return list<MethodCall> add* MethodCalls in source order */
	private function tag(string $code): array
	{
		$parser = (new ParserFactory())->createForNewestSupportedVersion();
		$stmts = $parser->parse($code);
		self::assertNotNull($stmts);
		$traverser = new NodeTraverser();
		$traverser->addVisitor(new ComponentAffectingNodeVisitor());
		$traverser->traverse($stmts);
		$calls = (new NodeFinder())->findInstanceOf($stmts, MethodCall::class);
		usort($calls, static fn (MethodCall $a, MethodCall $b): int => $a->getStartLine() <=> $b->getStartLine());

		return $calls;
	}

	public function testTagsAddKindAndConditionality(): void
	{
		$code = <<<'PHP'
<?php
class C {
	public function build($form, $c, $x): void
	{
		$form->addText('a');
		if ($c) {
			$form->addText('b');
		}
		$x->addText('cc');
	}
}
PHP;
		$calls = $this->tag($code);
		self::assertCount(3, $calls);
		$t0 = $calls[0]->getAttribute(TaggedNode::ATTRIBUTE);
		$t1 = $calls[1]->getAttribute(TaggedNode::ATTRIBUTE);
		$t2 = $calls[2]->getAttribute(TaggedNode::ATTRIBUTE);
		self::assertInstanceOf(TaggedNode::class, $t0);
		self::assertInstanceOf(TaggedNode::class, $t1);
		self::assertInstanceOf(TaggedNode::class, $t2);
		self::assertSame('addText', $t0->getOperationKind());
		self::assertSame('addText', $t1->getOperationKind());
		self::assertSame('addText', $t2->getOperationKind());
		self::assertFalse($t0->getStructuralContext()->isConditional());
		self::assertTrue($t1->getStructuralContext()->isConditional());
		self::assertFalse($t2->getStructuralContext()->isConditional());
	}

	public function testTagsNonAddOperationKinds(): void
	{
		$code = <<<'PHP'
<?php
class C {
	public function build($form, $ctrl, $c, $z): void
	{
		$form['x'] = $ctrl;
		if ($c) {
			unset($form['y']);
		}
		$form->removeComponent($z);
	}
}
PHP;
		$parser = (new ParserFactory())->createForNewestSupportedVersion();
		$stmts = $parser->parse($code);
		self::assertNotNull($stmts);
		$traverser = new NodeTraverser();
		$traverser->addVisitor(new ComponentAffectingNodeVisitor());
		$traverser->traverse($stmts);
		$finder = new NodeFinder();

		$assign = $finder->findInstanceOf($stmts, Assign::class)[0];
		$tAssign = $assign->getAttribute(TaggedNode::ATTRIBUTE);
		self::assertInstanceOf(TaggedNode::class, $tAssign);
		self::assertSame('$offsetSet', $tAssign->getOperationKind());
		self::assertFalse($tAssign->getStructuralContext()->isConditional());

		$unset = $finder->findInstanceOf($stmts, Unset_::class)[0];
		$tUnset = $unset->getAttribute(TaggedNode::ATTRIBUTE);
		self::assertInstanceOf(TaggedNode::class, $tUnset);
		self::assertSame('$offsetUnset', $tUnset->getOperationKind());
		self::assertTrue($tUnset->getStructuralContext()->isConditional());

		$calls = $finder->findInstanceOf($stmts, MethodCall::class);
		usort($calls, static fn (MethodCall $a, MethodCall $b): int => $a->getStartLine() <=> $b->getStartLine());
		$tRemove = $calls[0]->getAttribute(TaggedNode::ATTRIBUTE);
		self::assertInstanceOf(TaggedNode::class, $tRemove);
		self::assertSame('removeComponent', $tRemove->getOperationKind());
		self::assertFalse($tRemove->getStructuralContext()->isConditional());
	}

}
