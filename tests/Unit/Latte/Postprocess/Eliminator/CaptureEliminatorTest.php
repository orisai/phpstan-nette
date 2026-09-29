<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Postprocess\Eliminator;

use OriPhpstan\Nette\Latte\Postprocess\Eliminator\CaptureEliminator;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\EliminatorRun;

final class CaptureEliminatorTest extends BaseTestCase
{

	public function testLatte31CaptureWhitespaceMinifierAndFilteredCaptureGuard(): void
	{
		$php = EliminatorRun::template(<<<'PHP'
		ob_start(fn() => '') /* pos 3:1 */;
		try {
			echo '	captured ';
			echo LR\HtmlHelpers::escapeText($x) /* pos 4:11 */;
			echo "\n";

		} finally {
			$ʟ_tmp = ob_get_length() ? new LR\Html(ob_get_clean()) : ob_get_clean();
		}
		$ʟ_fi = new LR\FilterInfo('html');
		$v = $ʟ_tmp;

		Latte\Essential\WhitespaceMinifier::start('html') /* pos 7:1 */;
		try {
			echo '	<div>';
			echo LR\HtmlHelpers::escapeText($x) /* pos 9:9 */;
		} finally {
			Latte\Essential\WhitespaceMinifier::end();
		}
		ob_start(fn() => '') /* pos 12:1 */;
		try {
			echo LR\HtmlHelpers::escapeText($x) /* pos 13:2 */;
		} finally {
			$ʟ_tmp = ob_get_length() ? new LR\Html(ob_get_clean()) : ob_get_clean();
		}
		$ʟ_fi = new LR\FilterInfo('html');
		$trimmed = $this->filters->filterContent('trim', $ʟ_fi, $ʟ_tmp);
		if ($ʟ_fi->contentType === 'html' && $trimmed !== '') $trimmed = new LR\Html($trimmed);
		ob_start(fn() => '');
		try {
			echo 'Hello ';
		} finally {
			$ʟ_tmp = ob_get_clean();
		}
		$ʟ_fi = new LR\FilterInfo('html');
		echo LR\Helpers::convertTo($ʟ_fi, 'html', $this->filters->filterContent('translate', $ʟ_fi, $ʟ_tmp)) /* pos 1:1 */;
		ob_start('Latte\Essential\Filters::spacelessHtmlHandler', 4096);
		try {
			echo 'latte 3.0 shape';
		} finally {
			ob_end_flush();
		}
PHP);

		// The filtered-capture and {translate} tails stay for FilterRewriter; the 3.0 handler shell is not a 3.1 shape.
		self::assertSame(EliminatorRun::printed(<<<'PHP'
        echo '	captured ';
        echo \Latte\Runtime\HtmlHelpers::escapeText($x);
        echo "\n";
        $v = \OriPhpstan\Nette\Latte\Runtime\Helpers::capturedString();
        echo '	<div>';
        echo \Latte\Runtime\HtmlHelpers::escapeText($x);
        echo \Latte\Runtime\HtmlHelpers::escapeText($x);
        $ʟ_tmp = \OriPhpstan\Nette\Latte\Runtime\Helpers::capturedString();
        $ʟ_fi = new \Latte\Runtime\FilterInfo('html');
        $trimmed = $this->filters->filterContent('trim', $ʟ_fi, $ʟ_tmp);
        echo 'Hello ';
        $ʟ_tmp = \OriPhpstan\Nette\Latte\Runtime\Helpers::capturedString();
        $ʟ_fi = new \Latte\Runtime\FilterInfo('html');
        echo \Latte\Runtime\Helpers::convertTo($ʟ_fi, 'html', $this->filters->filterContent('translate', $ʟ_fi, $ʟ_tmp));
        \ob_start('Latte\Essential\Filters::spacelessHtmlHandler', 4096);
        try {
            echo 'latte 3.0 shape';
        } finally {
            \ob_end_flush();
        }
PHP), self::eliminate(ShapeFamily::LATTE_31, $php));
	}

	public function testLatte30SpacelessHandlerShellOnly(): void
	{
		$php = EliminatorRun::template(<<<'PHP'
		ob_start('Latte\Essential\Filters::spacelessHtmlHandler', 4096) /* line 7 */;
		try {
			echo '	<div>';
			echo LR\Filters::escapeHtmlText($x) /* line 9 */;
		} finally {
			ob_end_flush();
		}
		ob_start('Latte\Runtime\Filters::spacelessHtmlHandler', 4096);
		try {
			echo 'latte 2 shape';
		} finally {
			ob_end_flush();
		}
		Latte\Essential\WhitespaceMinifier::start('html');
		try {
			echo 'latte 3.1 shape';
		} finally {
			Latte\Essential\WhitespaceMinifier::end();
		}
		ob_start(function () {});
		try {
			echo 'latte 2 shape';
		} finally {
			$ʟ_tmp = ob_get_length() ? new LR\Html(ob_get_clean()) : ob_get_clean();
		}
		$ʟ_fi = new LR\FilterInfo('html');
		$v = $ʟ_tmp;
		if ($ʟ_fi->contentType === 'html' && $v !== '') $v = new LR\Html($v);
PHP);

		self::assertSame(EliminatorRun::printed(<<<'PHP'
        echo '	<div>';
        echo \Latte\Runtime\Filters::escapeHtmlText($x);
        \ob_start('Latte\Runtime\Filters::spacelessHtmlHandler', 4096);
        try {
            echo 'latte 2 shape';
        } finally {
            \ob_end_flush();
        }
        \Latte\Essential\WhitespaceMinifier::start('html');
        try {
            echo 'latte 3.1 shape';
        } finally {
            \Latte\Essential\WhitespaceMinifier::end();
        }
        \ob_start(function () {
        });
        try {
            echo 'latte 2 shape';
        } finally {
            $ʟ_tmp = \ob_get_length() ? new \Latte\Runtime\Html(\ob_get_clean()) : \ob_get_clean();
        }
        $ʟ_fi = new \Latte\Runtime\FilterInfo('html');
        $v = $ʟ_tmp;
        if ($ʟ_fi->contentType === 'html' && $v !== '') {
            $v = new \Latte\Runtime\Html($v);
        }
PHP), self::eliminate(ShapeFamily::LATTE_30, $php));
	}

	public function testLatte2CaptureAndSpacelessShells(): void
	{
		$php = EliminatorRun::template(<<<'PHP'
		ob_start(function () {}) /* line 3 */;
		try {
			echo LR\Filters::escapeHtmlText($x) /* line 4 */;
		} finally {
			$ʟ_tmp = ob_get_length() ? new LR\Html(ob_get_clean()) : ob_get_clean();
		}
		$ʟ_fi = new LR\FilterInfo('html');
		$v = $ʟ_tmp;
		ob_start('Latte\Runtime\Filters::spacelessHtmlHandler', 4096) /* line 7 */;
		try {
			echo 'spaceless';
		} finally {
			ob_end_flush();
		}
		ob_start(fn() => '');
		try {
			echo 'latte 3 shape';
		} finally {
			$ʟ_tmp = ob_get_length() ? new LR\Html(ob_get_clean()) : ob_get_clean();
		}
PHP);

		self::assertSame(EliminatorRun::printed(<<<'PHP'
        echo \Latte\Runtime\Filters::escapeHtmlText($x);
        $v = \OriPhpstan\Nette\Latte\Runtime\Helpers::capturedString();
        echo 'spaceless';
        \ob_start(fn() => '');
        try {
            echo 'latte 3 shape';
        } finally {
            $ʟ_tmp = \ob_get_length() ? new \Latte\Runtime\Html(\ob_get_clean()) : \ob_get_clean();
        }
PHP), self::eliminate(ShapeFamily::LATTE_2, $php));
	}

	private static function eliminate(string $latteLine, string $php): string
	{
		return EliminatorRun::apply($php, new CaptureEliminator(EliminatorRun::family($latteLine)));
	}

}
