<?php

use Latte\Runtime as LR;
final class LatteTpl_fixtures_forms_macros_latte_257f81ab extends \Latte\Runtime\Template
{
    /**
     * @return array{}
     */
    public function latteMain(): array
    {
        $form = \OriPhpstan\Nette\Latte\Runtime\Helpers::form('f');
        echo '<form';
        echo '>
	<input';
        $latteInput = $_input = \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('x');
        echo $latteInput->getControlPart()->attributes();
        echo '>
	';
        if ($latteLabel = \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('x')->getLabel()) {
            echo $latteLabel;
        }
        echo '
	';
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('x')->getError();
        echo "\n";
        echo '</form>

';
        $form = \OriPhpstan\Nette\Latte\Runtime\Helpers::form('f2');
        echo '
	';
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('y')->getControl();
        echo "\n";
        $formContainer = \OriPhpstan\Nette\Latte\Runtime\Helpers::formContainer('c');
        echo '		';
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('z')->getControl();
        echo "\n";
        echo "\n";
        return [];
    }
    public function lattePrepare(): void
    {
        \Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
    }
}