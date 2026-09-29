<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Postprocess\Eliminator;

use OriPhpstan\Nette\Latte\Postprocess\Eliminator\AttrShellEliminator;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\EliminatorRun;

// Inputs are the committed raw snapshots' n:attribute shapes; each case also carries another
// line's shape to pin that only the bound family's table is consulted.
final class AttrShellEliminatorTest extends BaseTestCase
{

	public function testLatte31TagTempsAttrsAndIfcontent(): void
	{
		$php = EliminatorRun::template(<<<'PHP'
		$ʟ_tag = '';
		if ($cond) /* pos 14:6 */ {
			$ʟ_tag = '</div>' . $ʟ_tag;
			echo '<div>';
		}
		$ʟ_tags[0] = $ʟ_tag;
		echo 'tag-if content';
		echo $ʟ_tags[0];
		echo ($ʟ_tmp = array_filter([$cond ? 'active' : null, $activeClass, 'base'])) ? ' class="' . LR\HtmlHelpers::escapeAttr(implode(" ", array_unique($ʟ_tmp))) . '"' : "" /* pos 16:6 */;
		$ʟ_tmp = ['data-x' => $cond ? 1 : null];
		echo Latte\Essential\Nodes\NAttrNode::attrs($ʟ_tmp, false) /* pos 20:6 */;
		$ʟ_tag = '';
		$ʟ_tmp = LR\HtmlHelpers::validateTagChange($tagName, 'div');
		$ʟ_tag = '</' . $ʟ_tmp . '>' . $ʟ_tag;
		echo '<', $ʟ_tmp /* pos 22:1 */;
		echo '>';
		$ʟ_tags[1] = $ʟ_tag;
		echo 'tag';
		echo $ʟ_tags[1];
		ob_start(fn() => '');
		try {
			echo '<p>';
			ob_start();
			try {
				if ($cond) /* pos 24:16 */ {
					echo 'x';
				}
			} finally {
				$ʟ_ifc[0] = rtrim(ob_get_flush()) === '';
			}
			echo '</p>';
		} finally {
			if ($ʟ_ifc[0] ?? null) {
				ob_end_clean();
			} else {
				echo ob_get_clean();
			}
		}
		echo $this->global->uiNonce ? " nonce=\"{$this->global->uiNonce}\"" : "";
		$ʟ_tmp = ['data-y' => 1];
		echo LR\Filters::htmlAttributes(isset($ʟ_tmp[0]) && is_array($ʟ_tmp[0]) ? $ʟ_tmp[0] : $ʟ_tmp);
		ob_start(function () {});
		try {
			echo '<i>';
			ob_start();
			try {
				echo 'latte 2 shape';
			} finally {
				$ʟ_ifc[1] = rtrim(ob_get_flush()) === '';
			}
		} finally {
			if ($ʟ_ifc[1] ?? null) {
				ob_end_clean();
			} else {
				echo ob_get_clean();
			}
		}
PHP);

		self::assertSame(EliminatorRun::printed(<<<'PHP'
        $latteTag = '';
        if ($cond) {
            $latteTag = '</div>' . $latteTag;
            echo '<div>';
        }
        $latteTag0 = $latteTag;
        echo 'tag-if content';
        echo $latteTag0;
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::classes([$cond ? 'active' : \null, $activeClass, 'base']);
        echo \Latte\Essential\Nodes\NAttrNode::attrs(['data-x' => $cond ? 1 : \null], \false);
        $latteTag = '';
        $latteTagName = \Latte\Runtime\HtmlHelpers::validateTagChange($tagName, 'div');
        $latteTag = '</' . $latteTagName . '>' . $latteTag;
        echo '<', $latteTagName;
        echo '>';
        $latteTag1 = $latteTag;
        echo 'tag';
        echo $latteTag1;
        echo '<p>';
        if ($cond) {
            echo 'x';
        }
        echo '</p>';
        $ʟ_tmp = ['data-y' => 1];
        echo \Latte\Runtime\Filters::htmlAttributes(isset($ʟ_tmp[0]) && \is_array($ʟ_tmp[0]) ? $ʟ_tmp[0] : $ʟ_tmp);
        \ob_start(function () {
        });
        try {
            echo '<i>';
            \ob_start();
            try {
                echo 'latte 2 shape';
            } finally {
                $ʟ_ifc[1] = \rtrim(\ob_get_flush()) === '';
            }
        } finally {
            if ($ʟ_ifc[1] ?? \null) {
                \ob_end_clean();
            } else {
                echo \ob_get_clean();
            }
        }
PHP), self::eliminate(ShapeFamily::LATTE_31, $php));
	}

	public function testLatte30IndexedTagTempAndTagChange(): void
	{
		$php = EliminatorRun::template(<<<'PHP'
		$ʟ_tag[0] = '';
		if ($cond) /* line 14 */ {
			$ʟ_tag[0] = '</div>' . $ʟ_tag[0];
			echo '<div>';
		}
		echo 'tag-if content';
		echo $ʟ_tag[0];
		$ʟ_tmp = ['data-x' => $cond ? 1 : null];
		echo Latte\Essential\Nodes\NAttrNode::attrs($ʟ_tmp, false) /* line 20 */;
		$ʟ_tag[1] = '';
		$ʟ_tmp = LR\HtmlHelpers::validateTagChange($tagName, 'div');
		$ʟ_tag[1] = '</' . $ʟ_tmp . '>' . $ʟ_tag[1];
		echo '<', $ʟ_tmp /* line 22 */;
		echo '>tag';
		echo $ʟ_tag[1];
		ob_start(fn() => '');
		try {
			echo '<p>';
			ob_start();
			try {
				echo 'x';
			} finally {
				$ʟ_ifc[0] = rtrim(ob_get_flush()) === '';
			}
			echo '</p>';
		} finally {
			if ($ʟ_ifc[0] ?? null) {
				ob_end_clean();
			} else {
				echo ob_get_clean();
			}
		}
		$ʟ_tags[1] = 'latte 3.1 shape';
		echo $ʟ_tags[1];
PHP);

		self::assertSame(EliminatorRun::printed(<<<'PHP'
        $latteTag0 = '';
        if ($cond) {
            $latteTag0 = '</div>' . $latteTag0;
            echo '<div>';
        }
        echo 'tag-if content';
        echo $latteTag0;
        echo \Latte\Essential\Nodes\NAttrNode::attrs(['data-x' => $cond ? 1 : \null], \false);
        $latteTag1 = '';
        $latteTagName = \Latte\Runtime\HtmlHelpers::validateTagChange($tagName, 'div');
        $latteTag1 = '</' . $latteTagName . '>' . $latteTag1;
        echo '<', $latteTagName;
        echo '>tag';
        echo $latteTag1;
        echo '<p>';
        echo 'x';
        echo '</p>';
        $ʟ_tags[1] = 'latte 3.1 shape';
        echo $ʟ_tags[1];
PHP), self::eliminate(ShapeFamily::LATTE_30, $php));
	}

	public function testLatte2TagTempHtmlAttributesAndIfcontent(): void
	{
		$php = EliminatorRun::template(<<<'PHP'
		$ʟ_tmp = ['data-x' => $cond ? 1 : null];
		echo LR\Filters::htmlAttributes(isset($ʟ_tmp[0]) && is_array($ʟ_tmp[0]) ? $ʟ_tmp[0] : $ʟ_tmp) /* line 20 */;
		$ʟ_tag[0] = ($tagName) ?? 'div';
		Latte\Runtime\Filters::checkTagSwitch('div', $ʟ_tag[0]);
		echo '<';
		echo $ʟ_tag[0];
		echo '>tag</';
		echo $ʟ_tag[0];
		echo '>';
		ob_start(function () {});
		try {
			echo '<p>';
			ob_start();
			try {
				echo 'x';
			} finally {
				$ʟ_ifc[1] = rtrim(ob_get_flush()) === '';
			}
			echo '</p>';
		} finally {
			if ($ʟ_ifc[1] ?? null) {
				ob_end_clean();
			} else {
				echo ob_get_clean();
			}
		}
		$ʟ_tmp = ['data-y' => 1];
		echo Latte\Essential\Nodes\NAttrNode::attrs($ʟ_tmp, false);
		$ʟ_tmp = LR\HtmlHelpers::validateTagChange($tagName, 'div');
		echo '<', $ʟ_tmp;
		ob_start(fn() => '');
		try {
			echo 'latte 3 shape';
		} finally {
			if ($ʟ_ifc[0] ?? null) {
				ob_end_clean();
			} else {
				echo ob_get_clean();
			}
		}
PHP);

		self::assertSame(EliminatorRun::printed(<<<'PHP'
        echo \Latte\Runtime\Filters::htmlAttributes(['data-x' => $cond ? 1 : \null]);
        $latteTag0 = $tagName ?? 'div';
        \Latte\Runtime\Filters::checkTagSwitch('div', $latteTag0);
        echo '<';
        echo $latteTag0;
        echo '>tag</';
        echo $latteTag0;
        echo '>';
        echo '<p>';
        echo 'x';
        echo '</p>';
        $ʟ_tmp = ['data-y' => 1];
        echo \Latte\Essential\Nodes\NAttrNode::attrs($ʟ_tmp, \false);
        $ʟ_tmp = \Latte\Runtime\HtmlHelpers::validateTagChange($tagName, 'div');
        echo '<', $ʟ_tmp;
        \ob_start(fn() => '');
        try {
            echo 'latte 3 shape';
        } finally {
            if ($ʟ_ifc[0] ?? \null) {
                \ob_end_clean();
            } else {
                echo \ob_get_clean();
            }
        }
PHP), self::eliminate(ShapeFamily::LATTE_2, $php));
	}

	private static function eliminate(string $latteLine, string $php): string
	{
		return EliminatorRun::apply($php, new AttrShellEliminator(EliminatorRun::family($latteLine)));
	}

}
