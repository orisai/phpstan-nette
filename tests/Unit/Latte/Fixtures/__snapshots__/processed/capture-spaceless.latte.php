<?php

use Latte\Runtime as LR;
final class LatteTpl_fixtures_capture_spaceless_latte_520212b0 extends \Latte\Runtime\Template
{
    /**
     * @param string $x
     * @return array{}
     */
    public function latteMain($x): array
    {
        echo "\n";
        echo '	captured ';
        echo $x;
        echo "\n";
        $v = \OriPhpstan\Nette\Latte\Runtime\Helpers::capturedString();
        echo "\n";
        echo '	<div>
		<span>';
        echo $x;
        echo '</span>
	</div>
';
        echo '
{literal brace}

';
        \OriPhpstan\Nette\Latte\Runtime\Helpers::analyzed($x, '$x');
        return [];
    }
    /**
     * @param string $x
     */
    public function lattePrepare($x): void
    {
        \Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
    }
}