<?php

use Latte\Runtime as LR;
final class LatteTpl_fixtures_parameters_latte_031c1534 extends \Latte\Runtime\Template
{
    /**
     * @param string $name
     * @param int $age
     * @return array{}
     */
    public function latteMain($name, $age = 18): array
    {
        echo '
Hello ';
        echo $name;
        echo ', age ';
        echo $age;
        echo "\n";
        return [];
    }
    public function lattePrepare(): void
    {
        \Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
    }
}