<?php

use Latte\Runtime as LR;
final class LatteTpl_fixtures_layout_latte_c2b2d2f9 extends \Latte\Runtime\Template
{
    protected const BLOCKS = [['title' => 'blockTitle', 'content' => 'blockContent']];
    /**
     * @param string $title
     * @return array{}
     */
    public function latteMain($title): array
    {
        $subtitle = 'sub';
        echo "\n";
        $this->blockTitle($title);
        echo '

';
        $this->blockContent($title);
        echo "\n";
        return [];
    }
    /**
     * @param string $title
     */
    public function lattePrepare($title): void
    {
        $this->parentName = 'parent.latte';
        $this->createTemplate('blocks-snippets.latte', $this->params, "import")->render();
        \Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
    }
    /**
     * @param string $title
     */
    public function blockTitle($title): void
    {
        echo $title;
        echo ' ';
        echo $subtitle;
    }
    /**
     * @param string $title
     */
    public function blockContent($title): void
    {
        $this->renderBlock('typed', ['value'] + [], 'html');
    }
}