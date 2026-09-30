<?php

use Latte\Runtime as LR;
final class LatteTpl_fixtures_extends_latte_4e7b036e extends \Latte\Runtime\Template
{
    protected const BLOCKS = [['title' => 'blockTitle']];
    /**
     * @param string $title
     * @return array{}
     */
    public function latteMain($title): array
    {
        echo "\n";
        $this->blockTitle($title);
        echo "\n";
        return [];
    }
    /**
     * @param string $title
     */
    public function lattePrepare($title): void
    {
        $this->parentName = 'parent.latte';
        \Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
    }
    /**
     * @param string $title
     */
    public function blockTitle($title): void
    {
        echo $title;
    }
}