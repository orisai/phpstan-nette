<?php

use Latte\Runtime as LR;
final class LatteTpl_fixtures_n_attributes_latte_366f42ea extends \Latte\Runtime\Template
{
    /**
     * @param bool $cond
     * @param array $items
     * @param string $activeClass
     * @param string $tagName
     * @return array{}
     */
    public function latteMain($cond, $items, $activeClass, $tagName): array
    {
        echo "\n";
        if ($cond) {
            echo '<div>shown</div>
';
        }
        echo '
<ul>
';
        $iterations = 0;
        foreach ($items as $item) {
            echo '	<li>';
            echo $item;
            echo '</li>
';
            $iterations++;
        }
        echo '</ul>

<div>';
        $iterations = 0;
        foreach ($items as $item) {
            echo $item;
            $iterations++;
        }
        echo '</div>

';
        if ($latteTagIf0 = $cond) {
            echo '<div>';
        }
        echo 'tag-if content';
        if ($latteTagIf0) {
            echo '</div>
';
        }
        echo '
<div';
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::classes([$cond ? 'active' : \null, $activeClass, 'base']);
        echo '>class</div>

<div';
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::classes([$cond ? 'form-group has-error' : 'form-group']);
        echo '>always-truthy class</div>

<div';
        echo \Latte\Runtime\Filters::htmlAttributes(['data-x' => $cond ? 1 : \null]);
        echo '></div>

';
        $latteTag0 = $tagName ?? 'div';
        \Latte\Runtime\Filters::checkTagSwitch('div', $latteTag0);
        echo '<';
        echo $latteTag0;
        echo '>tag</';
        echo $latteTag0;
        echo '>

';
        echo '<p>';
        if ($cond) {
            echo 'x';
        }
        echo '</p>
';
        echo '
<script';
        echo '>window.x=1;</script>
';
        return [];
    }
    /**
     * @param bool $cond
     * @param array $items
     * @param string $activeClass
     * @param string $tagName
     */
    public function lattePrepare($cond, $items, $activeClass, $tagName): void
    {
        \Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
    }
}