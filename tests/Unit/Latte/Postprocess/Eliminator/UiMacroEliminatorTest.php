<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Postprocess\Eliminator;

use OriPhpstan\Nette\Latte\Postprocess\Eliminator\UiMacroEliminator;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\EliminatorRun;

// Inputs are the committed raw snapshots' UI shapes (nette/application 3.2 and 3.3 print the same
// Latte 3 code; the Latte 2 dynamic {control} shape is the if/else one).
final class UiMacroEliminatorTest extends BaseTestCase
{

	/**
	 * @dataProvider provideLatte3Lines
	 */
	public function testLatte3ControlShellsLinksAndBareUiFunctions(string $latteLine): void
	{
		$php = EliminatorRun::template(<<<'PHP'
		$ʟ_tmp = $this->global->uiControl->getComponent('menu');
		if ($ʟ_tmp instanceof Nette\Application\UI\Renderable) $ʟ_tmp->redrawControl(null, false);
		$ʟ_tmp->renderSub(['a', 'b' => 1]) /* pos 2:1 */;
		if (!is_object($ʟ_tmp = $obj)) $ʟ_tmp = $this->global->uiControl->getComponent($ʟ_tmp);
		if ($ʟ_tmp instanceof Nette\Application\UI\Renderable) $ʟ_tmp->redrawControl(null, false);
		$ʟ_tmp->renderPart([1, 'a' => 2]) /* pos 5:1 */;
		echo LR\HtmlHelpers::escapeAttr($this->global->uiControl->link(':Admin::Foo:bar', [1])) /* pos 7:10 */;
		echo $this->global->uiPresenter->link('Foo:bar') /* pos 9:10 */;
		if (isLinkCurrent('Foo:bar')) /* pos 4:1 */ {
			echo 'c';
		}
		if (isModuleCurrent('Admin')) {
			echo 'm';
		}
		if ($this->global->uiPresenter->isLinkCurrent($dest, mode: 'x')) {
			echo 'current';
		}
		if ($this->global->uiPresenter->getLastCreatedRequestFlag("current")) {
			echo 'flag';
		}
		if (is_object($obj)) $_tmp = $obj;
		else $_tmp = $this->global->uiControl->getComponent($obj);
		if ($_tmp instanceof Nette\Application\UI\Renderable) $_tmp->redrawControl(null, false);
		$_tmp->render();
PHP);

		// The bare isLinkCurrent()/isModuleCurrent() calls are Latte 3 function calls the fixed
		// compile engine does not know (UIExtension registers them only with a presenter); the
		// harvested engine resolves them.
		self::assertSame(EliminatorRun::printed(<<<'PHP'
        \OriPhpstan\Nette\Latte\Runtime\Helpers::component('menu');
        ['a', 'b' => 1];
        \OriPhpstan\Nette\Latte\Runtime\Helpers::component($obj);
        [1, 'a' => 2];
        echo \Latte\Runtime\HtmlHelpers::escapeAttr(\OriPhpstan\Nette\Latte\Runtime\Helpers::uiLink(':Admin::Foo:bar', [1]));
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::uiLink('Foo:bar');
        if (\isLinkCurrent('Foo:bar')) {
            echo 'c';
        }
        if (\isModuleCurrent('Admin')) {
            echo 'm';
        }
        \OriPhpstan\Nette\Latte\Runtime\Helpers::analyzed('x');
        if (\OriPhpstan\Nette\Latte\Runtime\Helpers::uiIsLinkCurrent($dest)) {
            echo 'current';
        }
        if (\OriPhpstan\Nette\Latte\Runtime\Helpers::uiIsLinkCurrent(null)) {
            echo 'flag';
        }
        if (\is_object($obj)) {
            $_tmp = $obj;
        } else {
            $_tmp = $this->global->uiControl->getComponent($obj);
        }
        if ($_tmp instanceof \Nette\Application\UI\Renderable) {
            $_tmp->redrawControl(\null, \false);
        }
        $_tmp->render();
PHP), self::eliminate($latteLine, $php));
	}

	public function testLatte2ControlShellsAndIfCurrent(): void
	{
		$php = EliminatorRun::template(<<<'PHP'
		/* line 2 */ $_tmp = $this->global->uiControl->getComponent("menu");
		if ($_tmp instanceof Nette\Application\UI\Renderable) $_tmp->redrawControl(null, false);
		$_tmp->renderSub(['a', 'b' => 1]);
		/* line 3 */ if (is_object($obj)) $_tmp = $obj;
		else $_tmp = $this->global->uiControl->getComponent($obj);
		if ($_tmp instanceof Nette\Application\UI\Renderable) $_tmp->redrawControl(null, false);
		$_tmp->render();
		echo LR\Filters::escapeHtmlAttr($this->global->uiControl->link("Foo:baz", [1, 'x' => 2])) /* line 11 */;
		if ($this->global->uiPresenter->isLinkCurrent($dest, ['mode' => 'x'])) {
			echo 'current';
		}
		if (!is_object($ʟ_tmp = $obj)) $ʟ_tmp = $this->global->uiControl->getComponent($ʟ_tmp);
		if ($ʟ_tmp instanceof Nette\Application\UI\Renderable) $ʟ_tmp->redrawControl(null, false);
		$ʟ_tmp->renderPart();
PHP);

		self::assertSame(EliminatorRun::printed(<<<'PHP'
        \OriPhpstan\Nette\Latte\Runtime\Helpers::component("menu");
        ['a', 'b' => 1];
        \OriPhpstan\Nette\Latte\Runtime\Helpers::component($obj);
        echo \Latte\Runtime\Filters::escapeHtmlAttr(\OriPhpstan\Nette\Latte\Runtime\Helpers::uiLink("Foo:baz", [1, 'x' => 2]));
        \OriPhpstan\Nette\Latte\Runtime\Helpers::analyzed(['mode' => 'x']);
        if (\OriPhpstan\Nette\Latte\Runtime\Helpers::uiIsLinkCurrent($dest)) {
            echo 'current';
        }
        if (!\is_object($ʟ_tmp = $obj)) {
            $ʟ_tmp = $this->global->uiControl->getComponent($ʟ_tmp);
        }
        if ($ʟ_tmp instanceof \Nette\Application\UI\Renderable) {
            $ʟ_tmp->redrawControl(\null, \false);
        }
        $ʟ_tmp->renderPart();
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
		return EliminatorRun::apply($php, new UiMacroEliminator(EliminatorRun::family($latteLine)));
	}

}
