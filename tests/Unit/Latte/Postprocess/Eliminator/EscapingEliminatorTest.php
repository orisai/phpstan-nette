<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Postprocess\Eliminator;

use OriPhpstan\Nette\Latte\Postprocess\Eliminator\EscapingEliminator;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\EliminatorRun;

// Inputs are the shapes each Latte line generates: the committed raw snapshots plus the whole-
// attribute and escape helpers no fixture happens to exercise (Latte 3.1's HtmlHelpers/XmlHelpers).
final class EscapingEliminatorTest extends BaseTestCase
{

	public function testLatte31UnwrapsHelpersAndThePlainAttributeFormatterToTheValue(): void
	{
		$php = EliminatorRun::template(<<<'PHP'
		echo LR\HtmlHelpers::escapeText($x) /* pos 5:1 */;
		echo '<p';
		echo LR\HtmlHelpers::formatAttribute(' title', $a);
		echo LR\HtmlHelpers::formatAttribute(' href', ($this->filters->checkUrl)($url));
		echo '>';
		echo LR\Helpers::escapeJs($data);
		echo LR\Helpers::escapeCss($css);
		echo LR\Helpers::escapeICal($ical);
		echo LR\HtmlHelpers::escapeTag($tag);
		echo LR\HtmlHelpers::escapeComment($comment);
		echo LR\HtmlHelpers::escapeQuotes($quotes);
		echo LR\HtmlHelpers::escapeRawHtml($raw);
		echo LR\XmlHelpers::escapeText($x);
		echo LR\XmlHelpers::escapeAttr($x);
		echo LR\XmlHelpers::escapeTag($tag);
		echo LR\XmlHelpers::formatAttribute(' b', $x);
		echo LR\HtmlHelpers::escapeAttr($this->global->uiControl->link('this'));
		echo LR\Filters::escapeHtmlText($latte2Shape);
PHP);

		self::assertSame(EliminatorRun::printed(<<<'PHP'
        echo $x;
        echo '<p';
        echo $a;
        echo $url;
        echo '>';
        echo \Latte\Runtime\Helpers::escapeJs($data);
        echo $css;
        echo $ical;
        echo $tag;
        echo $comment;
        echo $quotes;
        echo $raw;
        echo $x;
        echo $x;
        echo $tag;
        echo $x;
        echo $this->global->uiControl->link('this');
        echo \Latte\Runtime\Filters::escapeHtmlText($latte2Shape);
PHP), self::eliminate(ShapeFamily::LATTE_31, $php));
	}

	// The list/style/data/json/aria/bool formatters accept arrays (data/json also objects), so their
	// value goes through a typed stand-in rather than a bare echo; the name part and the migration
	// flag are dropped.
	public function testLatte31ArrayAcceptingFormattersGoThroughTypedStandIns(): void
	{
		$php = EliminatorRun::template(<<<'PHP'
		echo '<div';
		echo LR\HtmlHelpers::formatListAttribute(' class', $classes);
		echo LR\HtmlHelpers::formatListAttribute(' class', ($this->filters->lower)($b));
		echo LR\HtmlHelpers::formatStyleAttribute(' style', $style);
		echo LR\HtmlHelpers::formatDataAttribute(' data-x', $data, true);
		echo LR\HtmlHelpers::formatJsonAttribute(' data-json', $json);
		echo LR\HtmlHelpers::formatAriaAttribute(' aria-label', $labels);
		echo LR\HtmlHelpers::formatBoolAttribute(' disabled', $flag);
		echo '>';
PHP);

		self::assertSame(EliminatorRun::printed(<<<'PHP'
        echo '<div';
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::htmlListAttribute($classes);
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::htmlListAttribute(($this->filters->lower)($b));
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::htmlStyleAttribute($style);
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::htmlDataAttribute($data);
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::htmlJsonAttribute($json);
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::htmlAriaAttribute($labels);
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::htmlBoolAttribute($flag);
        echo '>';
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
		echo LR\Filters::escapeHtmlTag($tag);
		echo LR\Filters::escapeHtmlComment($comment);
		echo LR\Filters::escapeHtmlQuotes($quotes);
		echo LR\Filters::escapeHtmlRawTextHtml($raw);
		echo LR\Filters::escapeXmlTag($tag);
		echo LR\Filters::escapeICal($ical);
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
        echo $tag;
        echo $comment;
        echo $quotes;
        echo $raw;
        echo $tag;
        echo $ical;
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
