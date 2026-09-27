<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Component\Attachment;

use OriPhpstan\Nette\Component\Attachment\AttachmentState;
use OriPhpstan\Nette\Component\Attachment\AttachmentTransitions;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use PhpParser\Node;
use PhpParser\ParserFactory;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function assert;

/**
 * The transitions, driven the way the walk drives them: statement by statement over a body's TOP
 * level, with the arms of a branching statement left to their own walk. A case whose expectation is
 * UNKNOWN is a degradation the design asks for, and each of those seeds a definite answer first so
 * the assertion proves the statement erased it rather than that nothing was ever recorded.
 */
final class AttachmentTransitionsTest extends BaseTestCase
{

	public function testAZeroArgumentConstructionIsProvablyDetached(): void
	{
		self::assertSame(Certainty::NEVER, $this->attachmentAfter('$c = new Control();', 'c'));
	}

	/**
	 * A construction handed anything at all may have been handed its parent - Nette's own
	 * `new Form($parent, $name)` runs `$parent->addComponent($this, $name)` - so only the
	 * zero-argument form claims anything.
	 */
	public function testAConstructionWithArgumentsClaimsNothing(): void
	{
		self::assertSame(Certainty::UNKNOWN, $this->attachmentAfter('$c = new Control($parent, "n");', 'c'));
	}

	public function testAddComponentAttachesItsArgument(): void
	{
		self::assertSame(
			Certainty::HAPPENS,
			$this->attachmentAfter("\$c = new Control();\n\$p->addComponent(\$c, 'n');", 'c'),
		);
	}

	public function testAddComponentLeavesTheContainerItIsCalledOnAlone(): void
	{
		$state = $this->walk("\$p->addComponent(\$c, 'n');", $this->seed('p', Certainty::HAPPENS));

		self::assertSame(Certainty::HAPPENS, $state->attachmentOf('p'));
	}

	/**
	 * The constructor-side fact the third approximation in the design rests on: the subject of
	 * `$parent->addComponent($this, $name)` is `$this`, and after it `$this` is attached.
	 */
	public function testAddComponentAttachesThis(): void
	{
		self::assertSame(Certainty::HAPPENS, $this->attachmentAfter('$parent->addComponent($this, $name);', 'this'));
	}

	public function testRemoveComponentDetachesItsArgument(): void
	{
		$state = $this->walk('$p->removeComponent($c);', $this->seed('c', Certainty::HAPPENS));

		self::assertSame(Certainty::NEVER, $state->attachmentOf('c'));
	}

	public function testSetParentNullDetachesTheReceiver(): void
	{
		$state = $this->walk('$c->setParent(null);', $this->seed('c', Certainty::HAPPENS));

		self::assertSame(Certainty::NEVER, $state->attachmentOf('c'));
	}

	/**
	 * A non-null argument does not prove an attach: the expression may itself evaluate to null, and
	 * validateParent() may refuse. Yes is not claimed from a spelling.
	 */
	public function testSetParentWithAContainerArgumentDegrades(): void
	{
		$state = $this->walk('$c->setParent($p);', $this->seed('c', Certainty::NEVER));

		self::assertSame(Certainty::UNKNOWN, $state->attachmentOf('c'));
	}

	public function testTheOneArgumentGetComponentYieldsAnAttachedComponent(): void
	{
		self::assertSame(Certainty::HAPPENS, $this->attachmentAfter("\$c = \$p->getComponent('n');", 'c'));
	}

	public function testTheNonThrowingGetComponentClaimsNothing(): void
	{
		$state = $this->walk("\$c = \$p->getComponent('n', false);", $this->seed('c', Certainty::NEVER));

		self::assertSame(Certainty::UNKNOWN, $state->attachmentOf('c'));
	}

	/**
	 * offsetGet() delegates to getComponent(), but nothing syntactic tells `$container['x']` from
	 * `$row['x']` on a plain array, so the read degrades rather than claim a component is there.
	 */
	public function testAnArrayDimensionReadDegrades(): void
	{
		$state = $this->walk("\$c = \$p['n'];", $this->seed('c', Certainty::NEVER));

		self::assertSame(Certainty::UNKNOWN, $state->attachmentOf('c'));
	}

	public function testAnIndexReadLeavesTheIndexedReferenceAlone(): void
	{
		$state = $this->walk("unset(\$p['n']);", $this->seed('p', Certainty::HAPPENS));

		self::assertSame(Certainty::HAPPENS, $state->attachmentOf('p'));
	}

	public function testACallOnTheReferenceThatIsNotSetParentMovesNothing(): void
	{
		self::assertSame(
			Certainty::NEVER,
			$this->attachmentAfter("\$c = new Control();\n\$c->setDisabled();", 'c'),
		);
	}

	public function testAChainRootedAtTheReferenceMovesNothing(): void
	{
		self::assertSame(
			Certainty::NEVER,
			$this->attachmentAfter("\$c = new Control();\n\$c->getForm()->addText('x');", 'c'),
		);
	}

	public function testAStoreIntoAPropertyEscapes(): void
	{
		self::assertSame(
			Certainty::UNKNOWN,
			$this->attachmentAfter("\$c = new Control();\n\$this->held = \$c;", 'c'),
		);
	}

	public function testPassingTheReferenceToAnUnreadCalleeEscapes(): void
	{
		self::assertSame(
			Certainty::UNKNOWN,
			$this->attachmentAfter("\$c = new Control();\nregister(\$c);", 'c'),
		);
	}

	/**
	 * An alias gives the component a second name, and an attach through either would leave the other
	 * holding a stale definite answer, so both names degrade at the assignment.
	 */
	public function testAliasingDegradesBothNames(): void
	{
		$state = $this->walk("\$c = new Control();\n\$d = \$c;", AttachmentState::initial());

		self::assertSame(Certainty::UNKNOWN, $state->attachmentOf('c'));
		self::assertSame(Certainty::UNKNOWN, $state->attachmentOf('d'));
	}

	public function testACaptureIntoAClosureEscapesRatherThanApplyingTheClosureBody(): void
	{
		self::assertSame(
			Certainty::UNKNOWN,
			$this->attachmentAfter(
				"\$c = new Control();\n\$cb = function () use (\$c, \$p) { \$p->addComponent(\$c, 'n'); };",
				'c',
			),
		);
	}

	/**
	 * The arrow-function spelling captures implicitly, so the reference is mentioned exactly once and
	 * only the closure boundary keeps the body's attach from being read as this statement's. Without
	 * it the walk would claim Yes at the point the callable is WRITTEN, for a body that runs whenever
	 * someone later invokes it - or never.
	 */
	public function testAnAttachInsideAnArrowFunctionBodyIsNotThisStatementsAttach(): void
	{
		self::assertSame(
			Certainty::UNKNOWN,
			$this->attachmentAfter("\$c = new Control();\n\$cb = fn () => \$p->addComponent(\$c, 'n');", 'c'),
		);
	}

	/**
	 * Two definite sites in one statement answer UNKNOWN instead of picking an evaluation order: an
	 * attach and a detach both run here, and which one the reference ends up under is a fact about
	 * argument order that this may not guess.
	 */
	public function testTwoDefiniteSitesInOneStatementDegrade(): void
	{
		self::assertSame(
			Certainty::UNKNOWN,
			$this->attachmentAfter("register(\$p->addComponent(\$c, 'n'), \$q->removeComponent(\$c));", 'c'),
		);
	}

	/**
	 * An assignment embedded in a larger expression is not read as a site: the enclosing call
	 * attaches what the assignment just constructed, and answering No from the inner half alone
	 * would be a definite claim about a component that is provably attached.
	 */
	public function testAnAssignmentEmbeddedInARegisteringCallDoesNotClaimDetached(): void
	{
		self::assertSame(
			Certainty::UNKNOWN,
			$this->attachmentAfter("\$p->addComponent(\$c = new Control(), 'n');", 'c'),
		);
	}

	/**
	 * A short-circuited or ternary operand runs on some paths only, so a transition inside one is
	 * refused for the same reason an `if` arm is - and the mention still counts, which is what turns
	 * the refusal into a degradation.
	 */
	public function testATransitionInAConditionalOperandDegrades(): void
	{
		$state = $this->walk("\$flag && \$p->addComponent(\$c, 'n');", $this->seed('c', Certainty::NEVER));

		self::assertSame(Certainty::UNKNOWN, $state->attachmentOf('c'));
	}

	public function testATransitionInATernaryBranchDegrades(): void
	{
		$state = $this->walk("\$flag ? \$p->addComponent(\$c, 'n') : null;", $this->seed('c', Certainty::NEVER));

		self::assertSame(Certainty::UNKNOWN, $state->attachmentOf('c'));
	}

	/**
	 * Only the header of a branching statement is read here. The arms reach the state through their
	 * own walk and meet at AttachmentState::join(), so reading them at the statement would apply an
	 * arm's transition on the path that skips it.
	 */
	public function testOnlyTheHeaderOfABranchingStatementIsRead(): void
	{
		$state = $this->walk(
			"if (\$flag) {\n\t\$p->addComponent(\$c, 'n');\n}",
			$this->seed('c', Certainty::NEVER),
		);

		self::assertSame(Certainty::NEVER, $state->attachmentOf('c'));
	}

	public function testAForeachValueVariableIsRebound(): void
	{
		$state = $this->walk(
			"foreach (\$p->getComponents() as \$c) {\n\t\$c->setDisabled();\n}",
			$this->seed('c', Certainty::HAPPENS),
		);

		self::assertSame(Certainty::UNKNOWN, $state->attachmentOf('c'));
	}

	/**
	 * A variable-variable names a reference this cannot read, so the statement is treated as having
	 * possibly moved everything.
	 */
	public function testAVariableVariableDropsTheWholeState(): void
	{
		$state = $this->walk('$$name = 1;', $this->seed('c', Certainty::HAPPENS));

		self::assertSame(Certainty::UNKNOWN, $state->attachmentOf('c'));
	}

	private function seed(string $reference, string $attachment): AttachmentState
	{
		return AttachmentState::initial()->withAttachment($reference, $attachment);
	}

	private function attachmentAfter(string $body, string $reference): string
	{
		return $this->walk($body, AttachmentState::initial())->attachmentOf($reference);
	}

	private function walk(string $body, AttachmentState $state): AttachmentState
	{
		foreach ($this->parse($body) as $statement) {
			$state = AttachmentTransitions::apply($state, $statement);
		}

		return $state;
	}

	/**
	 * @return array<Node\Stmt>
	 */
	private function parse(string $body): array
	{
		$statements = (new ParserFactory())->createForHostVersion()->parse('<?php ' . $body);
		assert($statements !== null);

		return $statements;
	}

}
