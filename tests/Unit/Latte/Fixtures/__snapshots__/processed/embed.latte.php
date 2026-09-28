<?php

use Latte\Runtime as LR;
final class LatteTpl_fixtures_embed_latte_1475771c extends \Latte\Runtime\Template
{
    protected const BLOCKS = [['sub' => 'blockSub'], ['content' => 'blockContent']];
    /**
     * @param string $mode
     * @return array{}
     */
    public function latteMain($mode): array
    {
        echo "\n";
        $this->blockSub($mode);
        echo '

';
        \OriPhpstan\Nette\Latte\Runtime\Helpers::analyzed(['mode' => $mode]);
        \OriPhpstan\Nette\Latte\Runtime\Helpers::embedTemplate('blocks-snippets.latte');
        echo '

';
        $this->blockSub($mode);
        echo "\n";
        return [];
    }
    /**
     * @param string $mode
     */
    public function lattePrepare($mode): void
    {
        \Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
    }
    /**
     * @param string $mode
     */
    public function blockSub($mode): void
    {
        echo 'embedded sub block';
    }
    /**
     * @param string $mode
     */
    public function blockContent($mode): void
    {
        echo '		override content ';
        echo $mode;
        echo "\n";
    }
}