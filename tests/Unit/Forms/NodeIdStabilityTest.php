<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms;

use OriPhpstan\Nette\Forms\Graph\ComponentAffectingNodeVisitor;
use OriPhpstan\Nette\Forms\Graph\TaggedNode;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;

final class NodeIdStabilityTest extends BaseTestCase
{

	/** @return array<string,string> map operationKind+arg → nodeId for tagged calls inside method build() */
	private function ids(string $code): array
	{
		$parser = (new ParserFactory())->createForNewestSupportedVersion();
		$stmts = $parser->parse($code);
		self::assertNotNull($stmts);
		$traverser = new NodeTraverser();
		$traverser->addVisitor(new ComponentAffectingNodeVisitor());
		$traverser->traverse($stmts);
		$out = [];
		foreach ((new NodeFinder())->findInstanceOf($stmts, MethodCall::class) as $mc) {
			$t = $mc->getAttribute(TaggedNode::ATTRIBUTE);
			if (!$t instanceof TaggedNode) {
				continue;
			}

			$argLit = isset($mc->getArgs()[0]) && $mc->getArgs()[0]->value instanceof String_
				? $mc->getArgs()[0]->value->value : '';
			$out[$t->getOperationKind() . '(' . $argLit . ')'] = $t->getNodeId();
		}

		return $out;
	}

	public function testReformatAndUnrelatedEarlierFunctionDoNotChangeIds(): void
	{
		$code1 = <<<'PHP'
<?php
class C {
	public function unrelated(): int { return 1; }
	public function build($form): void
	{
		$form->addText('a');
		$form->addInteger('b');
	}
}
PHP;
		$code2 = <<<'PHP'
<?php
class C {
	// a comment
	public function unrelated(): int
	{
		$q = 2 + 2;        // unrelated change
		return $q;
	}

	public function build($form): void
	{

		$form->addText('a');

		$form->addInteger('b');
	}
}
PHP;
		self::assertSame($this->ids($code1), $this->ids($code2));
	}

	public function testStructuralChangeChangesId(): void
	{
		$flat = <<<'PHP'
<?php
class C {
	public function build($form, $c): void
	{
		$form->addText('a');
	}
}
PHP;
		$wrapped = <<<'PHP'
<?php
class C {
	public function build($form, $c): void
	{
		if ($c) {
			$form->addText('a');
		}
	}
}
PHP;
		self::assertNotSame($this->ids($flat)['addText(a)'], $this->ids($wrapped)['addText(a)']);
	}

}
