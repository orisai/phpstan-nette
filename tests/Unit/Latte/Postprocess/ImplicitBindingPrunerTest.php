<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Postprocess;

use OriPhpstan\Nette\Latte\Postprocess\Eliminator\FormsMacroEliminator;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\IteratorEliminator;
use OriPhpstan\Nette\Latte\Postprocess\ImplicitBindingPruner;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\EliminatorRun;

final class ImplicitBindingPrunerTest extends BaseTestCase
{

	private const PLAIN_AND_WRAPPED_LOOPS = <<<'PHP'
		$iterations = 0;
		foreach ($items as $item) {
			echo $item;
			$iterations++;
		}
		$iterations = 0;
		foreach ($iterator = $ʟ_it = new LR\CachingIterator($items, $ʟ_it ?? null) as $item) {
			$this->renderBlock('b', get_defined_vars(), 'html');
			$iterations++;
		}
		$iterator = $ʟ_it = $ʟ_it->getParent();
PHP;

	public function testUnreadCountersAndIteratorAreDropped(): void
	{
		self::assertSame(EliminatorRun::printed(<<<'PHP'
        foreach ($items as $item) {
            echo $item;
        }
        foreach ($items as $item) {
            $this->renderBlock('b', \get_defined_vars(), 'html');
        }
PHP), self::pruneLatte2(self::PLAIN_AND_WRAPPED_LOOPS));
	}

	public function testReadCounterKeepsEveryCounter(): void
	{
		self::assertSame(EliminatorRun::printed(<<<'PHP'
        $iterations = 0;
        foreach ($items as $item) {
            echo $item;
            $iterations++;
        }
        $iterations = 0;
        foreach ($items as $item) {
            $this->renderBlock('b', \get_defined_vars(), 'html');
            $iterations++;
        }
        echo $iterations;
PHP), self::pruneLatte2(self::PLAIN_AND_WRAPPED_LOOPS . "\necho \$iterations;"));
	}

	public function testUserCounterNextToTheLoopIsKept(): void
	{
		self::assertSame(EliminatorRun::printed(<<<'PHP'
        $iterations = 5;
        foreach ($items as $item) {
            echo $item;
        }
PHP), self::pruneLatte2(<<<'PHP'
		$iterations = 5;
		$iterations = 0;
		foreach ($items as $item) {
			echo $item;
			$iterations++;
		}
PHP));
	}

	public function testForeachElseKeepsItsIteratorAndDropsItsCounter(): void
	{
		self::assertSame(EliminatorRun::printed(<<<'PHP'
        $iterator = new \Latte\Runtime\CachingIterator($items);
        foreach ($items as $item) {
            echo $item;
        }
        if ($iterator->isEmpty()) {
            echo 'empty';
        }
PHP), self::pruneLatte2(<<<'PHP'
		$iterations = 0;
		foreach ($iterator = $ʟ_it = new LR\CachingIterator($items, $ʟ_it ?? null) as $item) {
			echo $item;
			$iterations++;
		}
		if ($iterator->isEmpty()) {
			echo 'empty';
			$iterations++;
		}
		$iterator = $ʟ_it = $ʟ_it->getParent();
PHP));
	}

	public function testOuterIteratorOverwrittenByTheInnerOneIsDropped(): void
	{
		self::assertSame(EliminatorRun::printed(<<<'PHP'
        foreach ($groups as $group) {
            $iterator = new \Latte\Runtime\CachingIterator($group);
            foreach ($group as $item) {
                echo $iterator->counter;
            }
        }
PHP), self::pruneLatte2(<<<'PHP'
		$iterations = 0;
		foreach ($iterator = $ʟ_it = new LR\CachingIterator($groups, $ʟ_it ?? null) as $group) {
			$iterations = 0;
			foreach ($iterator = $ʟ_it = new LR\CachingIterator($group, $ʟ_it ?? null) as $item) {
				echo $iterator->counter;
				$iterations++;
			}
			$iterator = $ʟ_it = $ʟ_it->getParent();
			$iterations++;
		}
		$iterator = $ʟ_it = $ʟ_it->getParent();
PHP));
	}

	public function testOuterIteratorReadOnAPathAroundTheInnerLoopIsKept(): void
	{
		self::assertSame(EliminatorRun::printed(<<<'PHP'
        $iterator = new \Latte\Runtime\CachingIterator($groups);
        foreach ($groups as $group) {
            if ($group) {
                $iterator = new \Latte\Runtime\CachingIterator($group);
                foreach ($group as $item) {
                    echo $iterator->counter;
                }
            }
            echo $iterator->counter;
        }
PHP), self::pruneLatte2(<<<'PHP'
		foreach ($iterator = $ʟ_it = new LR\CachingIterator($groups, $ʟ_it ?? null) as $group) {
			if ($group) {
				foreach ($iterator = $ʟ_it = new LR\CachingIterator($group, $ʟ_it ?? null) as $item) {
					echo $iterator->counter;
				}
				$iterator = $ʟ_it = $ʟ_it->getParent();
			}
			echo $iterator->counter;
		}
		$iterator = $ʟ_it = $ʟ_it->getParent();
PHP));
	}

	public function testIteratorReadInTheNextIterationAfterABreakIsKept(): void
	{
		self::assertSame(EliminatorRun::printed(<<<'PHP'
        $iterator = new \Latte\Runtime\CachingIterator($groups);
        foreach ($groups as $group) {
            if ($group) {
                break;
            }
        }
        echo $iterator->counter;
PHP), self::pruneLatte2(<<<'PHP'
		foreach ($iterator = $ʟ_it = new LR\CachingIterator($groups, $ʟ_it ?? null) as $group) {
			if ($group) {
				break;
			}
		}
		echo $iterator->counter;
PHP));
	}

	public function testUnreadFormBindingsKeepTheirValue(): void
	{
		self::assertSame(EliminatorRun::printed(<<<'PHP'
        \OriPhpstan\Nette\Latte\Runtime\Helpers::form('f');
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('x')->getControl();
        \OriPhpstan\Nette\Latte\Runtime\Helpers::formContainer('c');
        $this->global->formsStack[] = \is_object($ʟ_tmp = $d) ? $ʟ_tmp : \end($this->global->formsStack)[$ʟ_tmp];
        $form = $control['f'];
PHP), self::pruneLatte2(<<<'PHP'
		$form = $this->global->formsStack[] = $this->global->uiControl["f"];
		Nette\Bridges\FormsLatte\Runtime::initializeForm($form);
		echo end($this->global->formsStack)["x"]->getControl();
		$this->global->formsStack[] = $formContainer = end($this->global->formsStack)["c"];
		array_pop($this->global->formsStack);
		$formContainer = end($this->global->formsStack);
		$this->global->formsStack[] = $formContainer = is_object($ʟ_tmp = $d) ? $ʟ_tmp : end($this->global->formsStack)[$ʟ_tmp];
		array_pop($this->global->formsStack);
		$formContainer = end($this->global->formsStack);
		$form = $control['f'];
PHP));
	}

	public function testReadFormBindingsAreKept(): void
	{
		self::assertSame(EliminatorRun::printed(<<<'PHP'
        \OriPhpstan\Nette\Latte\Runtime\Helpers::form('a');
        $form = \OriPhpstan\Nette\Latte\Runtime\Helpers::form('f');
        echo $form->getName();
        $formContainer = \OriPhpstan\Nette\Latte\Runtime\Helpers::formContainer('c');
        echo $formContainer->getName();
PHP), self::pruneLatte2(<<<'PHP'
		$form = $this->global->formsStack[] = $this->global->uiControl["a"];
		Nette\Bridges\FormsLatte\Runtime::initializeForm($form);
		$form = $this->global->formsStack[] = $this->global->uiControl["f"];
		Nette\Bridges\FormsLatte\Runtime::initializeForm($form);
		echo $form->getName();
		$this->global->formsStack[] = $formContainer = end($this->global->formsStack)["c"];
		echo $formContainer->getName();
		array_pop($this->global->formsStack);
		$formContainer = end($this->global->formsStack);
PHP));
	}

	public function testUnreadCompilerTemporaryLeavesItsValueInPlace(): void
	{
		self::assertSame(
			EliminatorRun::printed(<<<'PHP'
        echo \htmlspecialchars(\OriPhpstan\Nette\Latte\Runtime\Helpers::snippetId("item-{$id}"));
        $f = function () use ($name) {
            $ʟ_nm = $name;
            echo $name;
        };
        $g = function () {
            $ʟ_nm = 1;
            echo $ʟ_nm;
        };
PHP),
			EliminatorRun::applyPruned(EliminatorRun::template(<<<'PHP'
		echo \htmlspecialchars(\OriPhpstan\Nette\Latte\Runtime\Helpers::snippetId($ʟ_nm = "item-{$id}"));
		$f = function () use ($name) {
			$ʟ_nm = $name;
			echo $name;
		};
		$g = function () {
			$ʟ_nm = 1;
			echo $ʟ_nm;
		};
PHP)),
		);
	}

	public function testOpaqueFunctionKeepsEveryBinding(): void
	{
		self::assertSame(EliminatorRun::printed(<<<'PHP'
        $iterations = 0;
        foreach ($items as $item) {
            echo $item;
            $iterations++;
        }
        \extract($vars);
PHP), self::pruneLatte2(<<<'PHP'
		$iterations = 0;
		foreach ($items as $item) {
			echo $item;
			$iterations++;
		}
		extract($vars);
PHP));
	}

	public function testEdgeManifestKeepsWhatTheIncludedBlockReads(): void
	{
		$stmts = EliminatorRun::eliminate(
			EliminatorRun::template(<<<'PHP'
		$iterations = 0;
		foreach ($iterator = $ʟ_it = new LR\CachingIterator($items, $ʟ_it ?? null) as $item) {
			\OriPhpstan\Nette\Latte\Runtime\Helpers::edgeScope('k', []);
			$this->renderBlock('b', get_defined_vars(), 'html');
			$iterations++;
		}
		$iterator = $ʟ_it = $ʟ_it->getParent();
PHP),
			new IteratorEliminator(EliminatorRun::family(ShapeFamily::LATTE_2)),
		);
		$anchor = (new NodeFinder())->findFirstInstanceOf($stmts, StaticCall::class);
		self::assertInstanceOf(StaticCall::class, $anchor);
		$anchor->setAttribute('latte.edgeManifest', ['iterator', 'iterations']);

		self::assertSame(EliminatorRun::printed(<<<'PHP'
        $iterations = 0;
        $iterator = new \Latte\Runtime\CachingIterator($items);
        foreach ($items as $item) {
            \OriPhpstan\Nette\Latte\Runtime\Helpers::edgeScope('k', []);
            $this->renderBlock('b', \get_defined_vars(), 'html');
            $iterations++;
        }
PHP), (new Standard())->prettyPrintFile((new ImplicitBindingPruner())->prune($stmts)));
	}

	private static function pruneLatte2(string $body): string
	{
		$family = EliminatorRun::family(ShapeFamily::LATTE_2);

		return EliminatorRun::applyPruned(
			EliminatorRun::template($body),
			new IteratorEliminator($family),
			new FormsMacroEliminator($family),
		);
	}

}
