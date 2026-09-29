<?php

use Latte\Runtime as LR;
final class LatteTpl_fixtures_probes_latte_4c7d2b2d extends \Latte\Runtime\Template
{
    protected const BLOCKS = [['wrapper' => 'blockWrapper']];
    /**
     * @param string $name
     * @param string $mode
     * @return array{}
     */
    public function latteMain($name, $mode): array
    {
        echo "\n";
        $this->addBlock($ʟ_nm = $name, 'html', [[$this, 'blockName']], \null);
        $this->renderBlock($ʟ_nm, []);
        echo '

';
        $this->blockWrapper($name, $mode);
        echo '

';
        \ob_start(function () {
        });
        try {
            echo 'anonymous filtered ';
            echo $mode;
        } finally {
            echo \Latte\Runtime\Filters::convertTo(\OriPhpstan\Nette\Latte\Runtime\Helpers::filterInfo(), 'html', \Latte\Runtime\Filters::upper(\ob_get_clean()));
        }
        echo '

';
        \ob_start(function () {
        });
        try {
            $this->createTemplate('declarations.latte', ['flag' => \true], "sandbox")->renderToContentType('html');
            echo \ob_get_clean();
        } catch (\Throwable $latteException) {
            if (isset($this->global->coreExceptionHandler)) {
                \ob_end_clean();
                ($this->global->coreExceptionHandler)($latteException, $this);
            } else {
                echo \ob_get_clean();
                throw $latteException;
            }
        }
        echo "\n";
        return [];
    }
    /**
     * @param string $name
     * @param string $mode
     */
    public function lattePrepare($name, $mode): void
    {
        $this->createTemplate('blocks-snippets.latte', $this->params, "import")->render();
        \Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
    }
    /**
     * @param string $name
     * @param string $mode
     */
    public function blockName($name, $mode): void
    {
        echo 'dynamic ';
        echo $name;
    }
    /**
     * @param string $name
     * @param string $mode
     */
    public function blockWrapper($name, $mode): void
    {
        $this->renderBlockParent('wrapper', []);
    }
}