<?php

use Latte\Runtime as LR;
final class LatteTpl_fixtures_ui_macros_latte_1e9a68ee extends \Latte\Runtime\Template
{
    /**
     * @param object $obj
     * @return array{}
     */
    public function latteMain($obj): array
    {
        echo "\n";
        \OriPhpstan\Nette\Latte\Runtime\Helpers::component("menu");
        echo "\n";
        \OriPhpstan\Nette\Latte\Runtime\Helpers::component($obj);
        [1, 'a' => 2];
        echo '
<a href="';
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::uiLink("this");
        echo '">this</a>

<a href="';
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::uiLink("Foo:bar");
        echo '">bar</a>

<a href="';
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::uiLink("Foo:baz", [1, 'x' => 2]);
        echo '">baz</a>
';
        return [];
    }
    /**
     * @param object $obj
     */
    public function lattePrepare($obj): void
    {
        \Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
    }
}