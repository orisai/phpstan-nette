<?php

use Latte\Runtime as LR;
final class LatteTpl_fixtures_blocks_snippets_latte_6640b14a extends \Latte\Runtime\Template
{
    protected const BLOCKS = [0 => ['content' => 'blockContent', 'typed' => 'blockTyped'], 'snippet' => ['s1' => 'blockS1', 's2' => 'blockS2', 'area1' => 'blockArea1', 'inner' => 'blockInner']];
    /**
     * @param string $x
     * @return array{}
     */
    public function latteMain($x): array
    {
        echo "\n";
        $this->blockContent($x);
        echo '


';
        $this->blockContent($x);
        echo "\n";
        $this->blockTyped($x, 'value');
        echo '
<div';
        echo ' id="' . \htmlspecialchars(\OriPhpstan\Nette\Latte\Runtime\Helpers::snippetId('s1')) . '"';
        echo '>';
        $this->blockS1($x);
        echo '</div>

<div id="';
        echo \htmlspecialchars(\OriPhpstan\Nette\Latte\Runtime\Helpers::snippetId('s2'));
        echo '">';
        $this->blockS2($x);
        echo '</div>

';
        $this->blockArea1($x);
        echo "\n";
        return [];
    }
    /**
     * @param string $x
     */
    public function lattePrepare($x): void
    {
        \Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
    }
    /**
     * @param string $x
     */
    public function blockContent($x): void
    {
        echo '	block body ';
        echo $x;
        echo "\n";
    }
    /**
     * @param string $x
     * @param string $p
     */
    public function blockTyped($x, $p): void
    {
        echo '	typed body ';
        echo $p;
        echo "\n";
    }
    /**
     * @param string $x
     */
    public function blockS1($x): void
    {
        echo 'snippet content';
    }
    /**
     * @param string $x
     */
    public function blockS2($x): void
    {
        echo '	explicit snippet
';
    }
    /**
     * @param string $x
     */
    public function blockArea1($x): void
    {
        echo '	<div id="';
        echo \htmlspecialchars(\OriPhpstan\Nette\Latte\Runtime\Helpers::snippetId('inner'));
        echo '">';
        $this->blockInner($x);
        echo '</div>
';
    }
    /**
     * @param string $x
     */
    public function blockInner($x): void
    {
        echo 'area snippet';
    }
}