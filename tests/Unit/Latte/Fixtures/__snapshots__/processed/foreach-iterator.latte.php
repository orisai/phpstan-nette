<?php

use Latte\Runtime as LR;
final class LatteTpl_fixtures_foreach_iterator_latte_898f2db1 extends \Latte\Runtime\Template
{
    /**
     * @param array $items
     * @param array $groups
     * @return array{}
     */
    public function latteMain($items, $groups): array
    {
        echo "\n";
        $iterations = 0;
        foreach ($items as $item) {
            echo '	';
            echo $item;
            echo "\n";
            $iterations++;
        }
        echo "\n";
        $iterator = new \Latte\Runtime\CachingIterator($items);
        foreach ($items as $key => $item) {
            echo '	';
            if ($iterator->isFirst()) {
                echo 'first ';
            }
            echo $item;
            if ($iterator->isLast()) {
                echo ' last';
            }
            echo "\n";
        }
        echo "\n";
        $iterator = new \Latte\Runtime\CachingIterator($groups);
        foreach ($groups as $group) {
            $iterator = new \Latte\Runtime\CachingIterator($group);
            foreach ($group as $item) {
                echo '		';
                if ($iterator->isFirst()) {
                    echo 'head ';
                }
                echo $item;
                if ($iterator->isLast()) {
                    echo ' tail';
                }
                echo "\n";
            }
        }
        echo "\n";
        $iterator = new \Latte\Runtime\CachingIterator($items);
        foreach ($items as $item) {
            do {
                echo '		';
                echo $item;
                echo "\n";
                if (!$iterator->hasNext() || !($item === $item)) {
                    break;
                }
                $iterator->next();
                [, $item] = [$iterator->key(), $iterator->current()];
            } while (\true);
            echo '	';
            if (!$iterator->isLast()) {
                echo ', ';
            }
            echo "\n";
        }
        return [];
    }
    /**
     * @param array $items
     * @param array $groups
     */
    public function lattePrepare($items, $groups): void
    {
        \Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
    }
}