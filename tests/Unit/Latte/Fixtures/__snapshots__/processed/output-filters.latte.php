<?php

use Latte\Runtime as LR;
final class LatteTpl_fixtures_output_filters_latte_903857f4 extends \Latte\Runtime\Template
{
    /**
     * @param string $x
     * @param string $name
     * @param \DateTimeImmutable $created
     * @return array{}
     */
    public function latteMain($x, $name, $created): array
    {
        echo "\n";
        echo $x;
        echo "\n";
        echo 1 + 2;
        echo "\n";
        echo \Latte\Runtime\Filters::upper($x);
        echo "\n";
        echo \Latte\Runtime\Filters::truncate($x, 10);
        echo "\n";
        echo \Latte\Runtime\Filters::date($created, 'j.n.Y');
        echo "\n";
        echo $x;
        echo "\n";
        echo \Latte\Runtime\Filters::truncate(\Latte\Runtime\Filters::upper($x), 10);
        echo "\n";
        echo '	';
        echo $x;
        echo "\n";
        $trimmed = \Latte\Runtime\Filters::trim(\OriPhpstan\Nette\Latte\Runtime\Helpers::filterInfo(), \OriPhpstan\Nette\Latte\Runtime\Helpers::capturedString());
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::translate('text');
        echo "\n";
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::translate('greeting');
        echo "\n";
        return [];
    }
    /**
     * @param string $x
     * @param string $name
     * @param \DateTimeImmutable $created
     */
    public function lattePrepare($x, $name, $created): void
    {
        \Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
    }
}