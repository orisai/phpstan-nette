<?php

use Latte\Runtime as LR;
final class LatteTpl_fixtures_control_flow_latte_c4f04f4b extends \Latte\Runtime\Template
{
    /**
     * @param bool $show
     * @param int $level
     * @param array $items
     * @param bool $flag
     * @param bool $showCaptured
     * @return array{}
     */
    public function latteMain($show, $level, $items, $flag, $showCaptured): array
    {
        echo "\n";
        if ($show) {
            echo '	shown
';
        } elseif ($level > 1) {
            echo '	high
';
        } else {
            echo '	hidden
';
        }
        echo "\n";
        if (isset($items[0])) {
            echo '	first set
';
        }
        echo "\n";
        $latteSwitch = $level;
        if (\false) {
        } elseif (\in_array($latteSwitch, [1], \true)) {
            echo '		one
';
        } elseif (\in_array($latteSwitch, [2, 3], \true)) {
            echo '		two or three
';
        } else {
            echo '		other
';
        }
        echo "\n";
        $i = 0;
        while ($i < 3) {
            $i++;
        }
        echo "\n";
        for ($j = 0; $j < 3; $j++) {
            echo '	loop ';
            echo $j;
            echo "\n";
        }
        echo "\n";
        try {
            echo '	risky
	';
            if ($flag) {
                throw new \Latte\Runtime\RollbackException();
            }
            echo "\n";
        } catch (\Throwable $latteException) {
            if (!$latteException instanceof \Latte\Runtime\RollbackException && isset($this->global->coreExceptionHandler)) {
                ($this->global->coreExceptionHandler)($latteException, $this);
            }
            echo '	caught
';
        }
        echo "\n";
        if (true) {
            $latteIfchanged1 = [$level];
            echo '	changed to ';
            echo $level;
            echo "\n";
        }
        echo "\n";
        $iterator = new \Latte\Runtime\CachingIterator($items);
        foreach ($items as $item) {
            if ($item === \null) {
                /* line 49 */
                continue;
            }
            if ($item === 'stop') {
                /* line 50 */
                break;
            }
            if ($item === 'skip') {
                $iterator->skipRound();
                continue;
            }
            echo '	';
            echo $item;
            echo "\n";
        }
        echo "\n";
        if ($showCaptured) {
            echo '	captured block
';
        }
        echo "\n";
        return [];
    }
    /**
     * @param bool $show
     * @param int $level
     * @param array $items
     * @param bool $flag
     * @param bool $showCaptured
     */
    public function lattePrepare($show, $level, $items, $flag, $showCaptured): void
    {
        \Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
    }
}