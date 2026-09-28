<?php

use Latte\Runtime as LR;
final class LatteTpl_fixtures_parameters_blocks_latte_3e63c928 extends \Latte\Runtime\Template
{
    protected const BLOCKS = [['b' => 'blockB']];
    /**
     * @param string $p
     * @return array{}
     */
    public function latteMain($p): array
    {
        $this->blockB($p);
        echo "\n";
        return [];
    }
    public function lattePrepare(): void
    {
        \Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
    }
    /**
     * @param string $p
     */
    public function blockB($p): void
    {
        echo $p;
    }
}