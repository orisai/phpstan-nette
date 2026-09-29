<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Postprocess\Eliminator;

use OriPhpstan\Nette\Latte\Postprocess\Eliminator\IteratorEliminator;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\EliminatorRun;

final class IteratorEliminatorTest extends BaseTestCase
{

	/**
	 * @dataProvider provideLatte3Lines
	 */
	public function testLatte3EssentialIteratorIsHoistedAndRestoresDropped(string $latteLine): void
	{
		$php = EliminatorRun::template(<<<'PHP'
		foreach ($iterator = $ʟ_it = new Latte\Essential\CachingIterator($groups, $ʟ_it ?? null) as $group) /* pos 12:1 */ {
			foreach ($iterator = $ʟ_it = new Latte\Essential\CachingIterator($group, $ʟ_it ?? null) as $item) /* pos 13:2 */ {
				echo LR\HtmlHelpers::escapeText($item) /* pos 14:23 */;
			}
			$iterator = $ʟ_it = $ʟ_it->getParent();
		}
		$iterator = $ʟ_it = $ʟ_it->getParent();
		$iterations = 0;
		foreach ($iterator = $ʟ_it = new Latte\Runtime\CachingIterator($items, $ʟ_it ?? null) as $item) {
			$iterations++;
		}
PHP);

		// Latte 3 has neither the Latte 2 iterator class nor the $iterations counter: both stay as written.
		self::assertSame(EliminatorRun::printed(<<<'PHP'
        $iterator = new \Latte\Essential\CachingIterator($groups);
        foreach ($groups as $group) {
            $iterator = new \Latte\Essential\CachingIterator($group);
            foreach ($group as $item) {
                echo \Latte\Runtime\HtmlHelpers::escapeText($item);
            }
        }
        $iterations = 0;
        foreach ($iterator = $ʟ_it = new \Latte\Runtime\CachingIterator($items, $ʟ_it ?? \null) as $item) {
            $iterations++;
        }
PHP), self::eliminate($latteLine, $php));
	}

	public function testLatte2RuntimeIteratorIsHoistedWithItsIterationsCounter(): void
	{
		$php = EliminatorRun::template(<<<'PHP'
		$iterations = 0;
		foreach ($iterator = $ʟ_it = new LR\CachingIterator($items, $ʟ_it ?? null) as $item) /* line 9 */ {
			echo LR\Filters::escapeHtmlText($item);
			$iterations++;
		}
		$iterator = $ʟ_it = $ʟ_it->getParent();
		foreach ($iterator = $ʟ_it = new Latte\Essential\CachingIterator($items, $ʟ_it ?? null) as $item) {
		}
PHP);

		self::assertSame(EliminatorRun::printed(<<<'PHP'
        $iterator = new \Latte\Runtime\CachingIterator($items);
        foreach ($items as $item) {
            echo \Latte\Runtime\Filters::escapeHtmlText($item);
        }
        foreach ($iterator = $ʟ_it = new \Latte\Essential\CachingIterator($items, $ʟ_it ?? \null) as $item) {
        }
PHP), self::eliminate(ShapeFamily::LATTE_2, $php));
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public function provideLatte3Lines(): iterable
	{
		yield 'latte 3.0' => [ShapeFamily::LATTE_30];
		yield 'latte 3.1' => [ShapeFamily::LATTE_31];
	}

	private static function eliminate(string $latteLine, string $php): string
	{
		return EliminatorRun::apply($php, new IteratorEliminator(EliminatorRun::family($latteLine)));
	}

}
