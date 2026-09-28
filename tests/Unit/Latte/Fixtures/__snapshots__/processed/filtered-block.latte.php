<?php

use Latte\Runtime as LR;
final class LatteTpl_fixtures_filtered_block_latte_7f2fe8d9 extends \Latte\Runtime\Template
{
    /**
     * @return array{}
     */
    public function latteMain(): array
    {
        if ($this->hasBlock("title")) {
            \ob_start(function () {
            });
            try {
                $this->renderBlock('title', [], 'html');
            } finally {
                echo \Latte\Runtime\Filters::convertTo(\OriPhpstan\Nette\Latte\Runtime\Helpers::filterInfo(), 'html', \Latte\Runtime\Filters::trim(\OriPhpstan\Nette\Latte\Runtime\Helpers::filterInfo(), \Latte\Runtime\Filters::stripTags(\OriPhpstan\Nette\Latte\Runtime\Helpers::filterInfo(), \ob_get_clean())));
            }
        }
        echo "\n";
        return [];
    }
    public function lattePrepare(): void
    {
        \Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
    }
}