<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Postprocess\Eliminator;

use OriPhpstan\Nette\Latte\Postprocess\Eliminator\BlockDispatchEliminator;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\EliminatorRun;

// Inputs are the committed raw snapshots' block shapes: {embed} without Latte 2's dead if (false)
// mirror, {include parent}, the dynamic {block $name} (3.0 callable array, 3.1 first-class
// callable, the same wrapper on a dynamic include-file name) and the anonymous filtered block's
// IIFE. The block methods carry the parameter list DeclarationInjector leaves them with, since the
// eliminators run after it.
final class BlockDispatchEliminatorTest extends BaseTestCase
{

	public function testLatte31EmbedParentDynamicBlockAndInlineClosure(): void
	{
		$php = self::classWithBlocks(<<<'PHP'
		$this->enterBlockLayer(1, get_defined_vars()) /* pos 5:1 */;
		try {
			$this->createTemplate('blocks-snippets.latte', ['mode' => $mode], "embed")->renderToContentType('html') /* pos 5:1 */;
		} finally {
			$this->leaveBlockLayer();
		}
		$this->enterBlockLayer(2, get_defined_vars()) /* pos 11:1 */;
		$this->copyBlockLayer();
		try {
			$this->renderBlock('sub', [], 'html') /* pos 11:1 */;
		} finally {
			$this->leaveBlockLayer();
		}
		$this->renderParentBlock('content', get_defined_vars()) /* pos 2:16 */;
		$this->addBlock($ʟ_nm = (LR\Helpers::stringOrNull($ʟ_tmp = $name) ?? throw new InvalidArgumentException(sprintf('Block name must be a string, %s given.', get_debug_type($ʟ_tmp)))), 'html', [$this->blockName(...)], 0);
		$this->renderBlock($ʟ_nm, get_defined_vars());
		$this->createTemplate(LR\Helpers::stringOrNull($ʟ_tmp = $file) ?? throw new InvalidArgumentException(sprintf('Template name must be a string, %s given.', get_debug_type($ʟ_tmp))), [] + $this->params, 'include')->renderToContentType('html');
		ob_start(fn() => '') /* pos 6:1 */;
		try {
			(function () {
				extract(func_get_arg(0));
				$this->renderBlock('title', [], 'html') /* pos 1:38 */;
				echo 'anon';
			})(get_defined_vars());
		} finally {
			$ʟ_fi = new LR\FilterInfo('html');
			echo LR\Helpers::convertTo($ʟ_fi, 'html', $this->filters->filterContent('upper', $ʟ_fi, ob_get_clean()));
		}
		$this->renderBlockParent('content', get_defined_vars());
		$this->enterBlockLayer(3, get_defined_vars());
		if (false) {
		}
		try {
			$this->renderBlock('sub', [], 'html');
		} finally {
			$this->leaveBlockLayer();
		}
PHP);

		self::assertSame(self::printedClassWithBlocks(<<<'PHP'
        \OriPhpstan\Nette\Latte\Runtime\Helpers::analyzed(['mode' => $mode]);
        \OriPhpstan\Nette\Latte\Runtime\Helpers::embedTemplate('blocks-snippets.latte');
        $this->blockSub();
        $this->renderParentBlock('content', []);
        $this->addBlock($ʟ_nm = $name, 'html', [$this->blockName(...)], 0);
        $this->renderBlock($ʟ_nm, []);
        $this->createTemplate($file, [] + $this->params, 'include')->renderToContentType('html');
        \ob_start(fn() => '');
        try {
            $this->renderBlock('title', [], 'html');
            echo 'anon';
        } finally {
            $ʟ_fi = new \Latte\Runtime\FilterInfo('html');
            echo \Latte\Runtime\Helpers::convertTo($ʟ_fi, 'html', $this->filters->filterContent('upper', $ʟ_fi, \ob_get_clean()));
        }
        $this->renderBlockParent('content', \get_defined_vars());
        $this->enterBlockLayer(3, \get_defined_vars());
        if (\false) {
        }
        try {
            $this->blockSub();
        } finally {
            $this->leaveBlockLayer();
        }
PHP), self::eliminate(ShapeFamily::LATTE_31, $php));
	}

	public function testLatte30DynamicBlockCallableArray(): void
	{
		$php = self::classWithBlocks(<<<'PHP'
		$this->addBlock($ʟ_nm = (LR\Helpers::stringOrNull($ʟ_tmp = $name) ?? throw new InvalidArgumentException(sprintf('Block name must be a string, %s given.', get_debug_type($ʟ_tmp)))), 'html', [[$this, 'blockName']], 0);
		$this->renderBlock($ʟ_nm, get_defined_vars());
		$this->enterBlockLayer(1, get_defined_vars()) /* line 5 */;
		try {
			$this->createTemplate('blocks-snippets.latte', [], "embed")->renderToContentType('html') /* line 5 */;
		} finally {
			$this->leaveBlockLayer();
		}
PHP);

		self::assertSame(self::printedClassWithBlocks(<<<'PHP'
        $this->addBlock($ʟ_nm = $name, 'html', [[$this, 'blockName']], 0);
        $this->renderBlock($ʟ_nm, []);
        \OriPhpstan\Nette\Latte\Runtime\Helpers::embedTemplate('blocks-snippets.latte');
PHP), self::eliminate(ShapeFamily::LATTE_30, $php));
	}

	public function testLatte2EmbedWithDeadIfAndParentBlock(): void
	{
		$php = self::classWithBlocks(<<<'PHP'
		$this->enterBlockLayer(1, get_defined_vars()) /* line 5 */;
		if (false) {
			$this->renderBlock('content', get_defined_vars()) /* line 6 */;
			echo "\n";
		}
		try {
			$this->createTemplate('blocks-snippets.latte', ['mode' => $mode], "embed")->renderToContentType('html') /* line 5 */;
		} finally {
			$this->leaveBlockLayer();
		}
		$this->enterBlockLayer(2, get_defined_vars()) /* line 11 */;
		if (false) {
		}
		$this->copyBlockLayer();
		try {
			$this->renderBlock('sub', [], 'html') /* line 11 */;
		} finally {
			$this->leaveBlockLayer();
		}
		$this->renderBlockParent('content', get_defined_vars()) /* line 2 */;
		$this->addBlock($ʟ_nm = $name, 'html', [[$this, 'blockName']], null);
		$this->renderBlock($ʟ_nm, get_defined_vars());
		$this->renderParentBlock('content', get_defined_vars());
		$this->enterBlockLayer(3, get_defined_vars());
		try {
			$this->renderBlock('sub', [], 'html');
		} finally {
			$this->leaveBlockLayer();
		}
		(function () {
			extract(func_get_arg(0));
			echo 'anon';
		})(get_defined_vars());
PHP);

		self::assertSame(self::printedClassWithBlocks(<<<'PHP'
        \OriPhpstan\Nette\Latte\Runtime\Helpers::analyzed(['mode' => $mode]);
        \OriPhpstan\Nette\Latte\Runtime\Helpers::embedTemplate('blocks-snippets.latte');
        $this->blockSub();
        $this->renderBlockParent('content', []);
        $this->addBlock($ʟ_nm = $name, 'html', [[$this, 'blockName']], \null);
        $this->renderBlock($ʟ_nm, []);
        $this->renderParentBlock('content', \get_defined_vars());
        $this->enterBlockLayer(3, \get_defined_vars());
        try {
            $this->blockSub();
        } finally {
            $this->leaveBlockLayer();
        }
        (function () {
            \extract(\func_get_arg(0));
            echo 'anon';
        })(\get_defined_vars());
PHP), self::eliminate(ShapeFamily::LATTE_2, $php));
	}

	private static function classWithBlocks(string $body): string
	{
		return "<?php\n\nuse Latte\\Runtime as LR;\n\nfinal class T extends Latte\\Runtime\\Template\n{\n"
			. "\tpublic function main(): void\n\t{\n" . $body . "\n\t}\n\n"
			. "\tpublic function blockSub(): void\n\t{\n\t\techo 'sub';\n\t}\n\n"
			. "\tpublic function blockName(): void\n\t{\n\t\techo 'dyn';\n\t}\n}\n";
	}

	private static function printedClassWithBlocks(string $body): string
	{
		return "<?php\n\nuse Latte\\Runtime as LR;\nfinal class T extends \\Latte\\Runtime\\Template\n{\n"
			. "    public function main(): void\n    {\n" . $body . "\n    }\n"
			. "    public function blockSub(): void\n    {\n        echo 'sub';\n    }\n"
			. "    public function blockName(): void\n    {\n        echo 'dyn';\n    }\n}";
	}

	private static function eliminate(string $latteLine, string $php): string
	{
		return EliminatorRun::apply($php, new BlockDispatchEliminator(EliminatorRun::family($latteLine)));
	}

}
