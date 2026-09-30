<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Postprocess\Eliminator;

use OriPhpstan\Nette\Latte\Postprocess\Eliminator\ControlFlowEliminator;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\EliminatorRun;

final class ControlFlowEliminatorTest extends BaseTestCase
{

	/**
	 * @dataProvider provideLatte3Lines
	 */
	public function testLatte3TryIfchangedSwitchAndCaptureIfShells(string $latteLine): void
	{
		$php = EliminatorRun::template(<<<'PHP'
		$ʟ_try[0] = [$ʟ_it ?? null];
		ob_start(fn() => '');
		try /* pos 37:1 */ {
			echo '	risky
';
			if ($flag) /* pos 39:2 */ {
				throw new Latte\Essential\RollbackException;
			}

		} catch (Throwable $ʟ_e) {
			ob_clean();
			if (!($ʟ_e instanceof Latte\Essential\RollbackException) && isset($this->global->coreExceptionHandler)) {
				($this->global->coreExceptionHandler)($ʟ_e, $this);
			}
			echo '	caught
';

		} finally {
			echo ob_get_clean();
			$iterator = $ʟ_it = $ʟ_try[0][0];
		}
		$ʟ_try[1] = [$ʟ_it ?? null];
		ob_start(fn() => '');
		try /* pos 2:1 */ {
			echo 'c';
		} catch (Throwable $ʟ_e) {
			ob_clean();
			if (!($ʟ_e instanceof Latte\Essential\RollbackException) && isset($this->global->coreExceptionHandler)) {
				($this->global->coreExceptionHandler)($ʟ_e, $this);
			}
		} finally {
			echo ob_get_clean();
			$iterator = $ʟ_it = $ʟ_try[1][0];
		}
		if (($ʟ_loc[1] ?? null) !== ($ʟ_tmp = [$level])) {
			$ʟ_loc[1] = $ʟ_tmp;
			echo '	changed to ';
		}
		$ʟ_switch = ($level) /* pos 19:1 */;
		if ($ʟ_switch === (1)) /* pos 20:2 */ {
			echo '		one';
		} elseif (in_array($ʟ_switch, [2, 3], true)) /* pos 22:2 */ {
			echo '		two or three';
		}
		ob_start(fn() => '') /* pos 55:1 */;
		try {
			echo '	captured block
';

		} finally {
			$ʟ_ifA = ob_get_clean();
		}
		if ($showCaptured) /* pos 55:1 */ {
			echo $ʟ_ifA;
		}
		if ($ʟ_if[0] = ($cond)) {
			echo '<div>';
		}
PHP);

		// n:tag-if has no $ʟ_if temp on Latte 3 (it reuses n:tag's $ʟ_tag): the Latte 2 rename does not fire.
		self::assertSame(EliminatorRun::printed(<<<'PHP'
        try {
            echo '	risky
';
            if ($flag) {
                throw new \Latte\Essential\RollbackException();
            }
        } catch (\Throwable $latteException) {
            if (!$latteException instanceof \Latte\Essential\RollbackException && isset($this->global->coreExceptionHandler)) {
                ($this->global->coreExceptionHandler)($latteException, $this);
            }
            echo '	caught
';
        }
        try {
            echo 'c';
        } catch (\Throwable $latteException) {
            if (!$latteException instanceof \Latte\Essential\RollbackException && isset($this->global->coreExceptionHandler)) {
                ($this->global->coreExceptionHandler)($latteException, $this);
            }
        }
        if (true) {
            $latteIfchanged1 = [$level];
            echo '	changed to ';
        }
        $latteSwitch = $level;
        if ($latteSwitch === 1) {
            echo '		one';
        } elseif (\in_array($latteSwitch, [2, 3], \true)) {
            echo '		two or three';
        }
        if ($showCaptured) {
            echo '	captured block
';
        }
        if ($ʟ_if[0] = $cond) {
            echo '<div>';
        }
PHP), self::eliminate($latteLine, $php));
	}

	public function testLatte2LeavesTheLatte3TryShellAndRenamesTagIf(): void
	{
		$php = EliminatorRun::template(<<<'PHP'
		$ʟ_try[0] = [$ʟ_it ?? null];
		ob_start(fn() => '');
		try {
			echo 'a';
		} catch (Throwable $ʟ_e) {
			ob_clean();
			echo 'b';
		} finally {
			echo ob_get_clean();
			$iterator = $ʟ_it = $ʟ_try[0][0];
		}
		if ($ʟ_if[0] = ($cond)) {
			echo '<div>';
		}
PHP);

		self::assertSame(EliminatorRun::printed(<<<'PHP'
        \ob_start(fn() => '');
        try {
            echo 'a';
        } catch (\Throwable $latteException) {
            \ob_clean();
            echo 'b';
        } finally {
            echo \ob_get_clean();
            $iterator = $ʟ_it = $ʟ_try[0][0];
        }
        if ($latteTagIf0 = $cond) {
            echo '<div>';
        }
PHP), self::eliminate(ShapeFamily::LATTE_2, $php));
	}

	// Feature::ScopedLoopVariables (3.1): the backup/unset/restore shell goes, the loop stays - also
	// nested in an else branch, which no statement-list normalisation reaches.
	public function testLatte31ScopedForeachShellIsReducedToTheLoop(): void
	{
		$php = EliminatorRun::template(<<<'PHP'
		if ($flag) {
			echo 'a';
		} else {
			try {
				$ʟ_fe_0 = get_defined_vars();
				unset($k, $item);

				foreach ($items as $k => $item) /* pos 2:1 */ {
					echo LR\HtmlHelpers::escapeText($item) /* pos 2:32 */;
				}

			} finally {
				unset($k, $item);
				if (array_key_exists('k', $ʟ_fe_0)) {
					$k = &$ʟ_fe_0['k'];
				}
				if (array_key_exists('item', $ʟ_fe_0)) {
					$item = &$ʟ_fe_0['item'];
				}

				unset($ʟ_fe_0);
			}
		}
PHP);

		self::assertSame(EliminatorRun::printed(<<<'PHP'
        if ($flag) {
            echo 'a';
        } else {
            foreach ($items as $k => $item) {
                echo \Latte\Runtime\HtmlHelpers::escapeText($item);
            }
        }
PHP), self::eliminate(ShapeFamily::LATTE_31, $php));

		self::assertStringContainsString(
			'$ʟ_fe_0 = \\get_defined_vars()',
			self::eliminate(ShapeFamily::LATTE_30, $php),
		);
		self::assertStringContainsString(
			'$ʟ_fe_0 = \\get_defined_vars()',
			self::eliminate(ShapeFamily::LATTE_2, $php),
		);
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
		return EliminatorRun::apply($php, new ControlFlowEliminator(EliminatorRun::family($latteLine)));
	}

}
