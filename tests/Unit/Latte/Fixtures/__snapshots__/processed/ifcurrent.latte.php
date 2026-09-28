<?php

use Latte\Runtime as LR;
final class LatteTpl_fixtures_ifcurrent_latte_90517d0b extends \Latte\Runtime\Template
{
    /**
     * @param string $dest
     * @param string $label
     * @return array{}
     */
    public function latteMain($dest, $label): array
    {
        echo "\n";
        \OriPhpstan\Nette\Latte\Runtime\Helpers::analyzed(['mode' => 'x']);
        if (\OriPhpstan\Nette\Latte\Runtime\Helpers::uiIsLinkCurrent($dest)) {
            echo '	';
            echo $label;
            echo "\n";
        }
        echo "\n";
        if (\OriPhpstan\Nette\Latte\Runtime\Helpers::uiIsLinkCurrent(null)) {
            echo '	';
            echo $label;
            echo "\n";
        }
        return [];
    }
    /**
     * @param string $dest
     * @param string $label
     */
    public function lattePrepare($dest, $label): void
    {
        \Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
    }
}