<?php

use Latte\Runtime as LR;
final class LatteTpl_fixtures_declarations_latte_101fb50a extends \Latte\Runtime\Template
{
    /**
     * @var int
     */
    private static $prop_0_y;
    /**
     * @var bool
     */
    private static $prop_1_flag;
    /**
     * @var string
     */
    private static $prop_2_late;
    /**
     * @param string $title
     * @param int|null $count
     * @param array<string> $tags
     * @param array<int, string> $labels
     * @param string $label
     * @return array{}
     */
    public function latteMain($title, $count, $tags, $labels, $label): array
    {
        echo "\n";
        $x = 1;
        self::$prop_0_y = 2;
        $y = self::$prop_0_y;
        $z ??= 3;
        $noop = \null;
        $noop = 1;
        echo $label;
        echo ' ';
        echo $x;
        echo ' ';
        echo $y;
        echo ' ';
        echo $z;
        echo "\n";
        self::$prop_1_flag = \false;
        $flag ??= self::$prop_1_flag;
        if ($flag) {
            echo 'on';
        }
        echo "\n";
        $late = self::$prop_2_late;
        echo $late;
        echo "\n";
        return [];
    }
    /**
     * @param string $title
     * @param int|null $count
     * @param array<string> $tags
     * @param array<int, string> $labels
     * @param string $label
     */
    public function lattePrepare($title, $count, $tags, $labels, $label): void
    {
        \Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
    }
}