<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Postprocess\Eliminator;

use OriPhpstan\Nette\Latte\Postprocess\Eliminator\EscapingEliminator;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\EliminatorRun;

// Inputs are the shapes the committed raw snapshots of each Latte line carry.
final class EscapingEliminatorTest extends BaseTestCase
{

	public function testLatte31UnwrapsHelpersAndWholeAttributeFormattersToTheValue(): void
	{
		$php = EliminatorRun::template(<<<'PHP'
		echo LR\HtmlHelpers::escapeText($x) /* pos 5:1 */;
		echo '<p';
		echo LR\HtmlHelpers::formatAttribute(' title', $a);
		echo LR\HtmlHelpers::formatListAttribute(' class', ($this->filters->lower)($b));
		echo LR\HtmlHelpers::formatDataAttribute(' data-x', $c, true);
		echo LR\HtmlHelpers::formatAttribute(' href', ($this->filters->checkUrl)($url));
		echo '>';
		echo LR\Helpers::escapeJs($data);
		echo LR\Helpers::escapeCss($css);
		echo LR\XmlHelpers::escapeText($x);
		echo LR\XmlHelpers::formatAttribute(' b', $x);
		echo LR\HtmlHelpers::escapeAttr($this->global->uiControl->link('this'));
		echo LR\Filters::escapeHtmlText($latte2Shape);
PHP);

		self::assertSame(EliminatorRun::printed(<<<'PHP'
        echo $x;
        echo '<p';
        echo $a;
        echo ($this->filters->lower)($b);
        echo $c;
        echo $url;
        echo '>';
        echo \Latte\Runtime\Helpers::escapeJs($data);
        echo $css;
        echo $x;
        echo $x;
        echo $this->global->uiControl->link('this');
        echo \Latte\Runtime\Filters::escapeHtmlText($latte2Shape);
PHP), self::eliminate(ShapeFamily::LATTE_31, $php));
	}

	public function testLatte30UnwrapsRuntimeFiltersAndTheCheckUrlFilter(): void
	{
		$php = EliminatorRun::template(<<<'PHP'
		echo LR\Filters::escapeHtmlText($x) /* line 5 */;
		echo '<a href="';
		echo LR\Filters::escapeHtmlAttr(($this->filters->checkUrl)($url));
		echo LR\Filters::escapeXmlAttr($x);
		echo LR\Filters::escapeXmlText($x);
		echo LR\Filters::escapeHtmlAttr(LR\Filters::escapeCss($d));
		echo LR\Filters::escapeHtmlAttr(LR\Filters::escapeJs($g));
		echo LR\HtmlHelpers::escapeText($latte31Shape);
		echo LR\Filters::safeUrl($latte2Shape);
PHP);

		self::assertSame(EliminatorRun::printed(<<<'PHP'
        echo $x;
        echo '<a href="';
        echo $url;
        echo $x;
        echo $x;
        echo $d;
        echo \Latte\Runtime\Filters::escapeJs($g);
        echo \Latte\Runtime\HtmlHelpers::escapeText($latte31Shape);
        echo \Latte\Runtime\Filters::safeUrl($latte2Shape);
PHP), self::eliminate(ShapeFamily::LATTE_30, $php));
	}

	public function testLatte2UnwrapsSafeUrlButNotTheLatte3Shapes(): void
	{
		$php = EliminatorRun::template(<<<'PHP'
		echo LR\Filters::escapeHtmlAttr(LR\Filters::safeUrl($url));
		echo LR\HtmlHelpers::escapeText($latte31Shape);
		echo LR\Filters::escapeHtmlAttr(($this->filters->checkUrl)($latte3Shape));
PHP);

		self::assertSame(EliminatorRun::printed(<<<'PHP'
        echo $url;
        echo \Latte\Runtime\HtmlHelpers::escapeText($latte31Shape);
        echo ($this->filters->checkUrl)($latte3Shape);
PHP), self::eliminate(ShapeFamily::LATTE_2, $php));
	}

	private static function eliminate(string $latteLine, string $php): string
	{
		return EliminatorRun::apply($php, new EscapingEliminator(EliminatorRun::family($latteLine)));
	}

}
