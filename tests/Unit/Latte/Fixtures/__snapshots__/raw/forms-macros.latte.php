<?php

use Latte\Runtime as LR;

final class LatteTpl_fixtures_forms_macros_latte_257f81ab extends Latte\Runtime\Template
{

	public function main(): array
	{
		extract($this->params);
		$form = $this->global->formsStack[] = $this->global->uiControl["f"] /* line 1 */;
		Nette\Bridges\FormsLatte\Runtime::initializeForm($form);
		echo '<form';
		echo Nette\Bridges\FormsLatte\Runtime::renderFormBegin(end($this->global->formsStack), [], false);
		echo '>
	<input';
		$ʟ_input = $_input = end($this->global->formsStack)["x"];
		echo $ʟ_input->getControlPart()->attributes() /* line 2 */;
		echo '>
	';
		if ($ʟ_label = end($this->global->formsStack)["x"]->getLabel()) echo $ʟ_label;
		echo '
	';
		echo LR\Filters::escapeHtmlText(end($this->global->formsStack)["x"]->getError()) /* line 4 */;
		echo "\n";
		echo Nette\Bridges\FormsLatte\Runtime::renderFormEnd(array_pop($this->global->formsStack), false) /* line 1 */;
		echo '</form>

';
		$form = $this->global->formsStack[] = $this->global->uiControl["f2"];
		Nette\Bridges\FormsLatte\Runtime::initializeForm($form);
		echo Nette\Bridges\FormsLatte\Runtime::renderFormBegin($form, []) /* line 7 */;
		echo '
	';
		echo end($this->global->formsStack)["y"]->getControl() /* line 8 */;
		echo "\n";
		$this->global->formsStack[] = $formContainer = end($this->global->formsStack)["c"] /* line 9 */;
		echo '		';
		echo end($this->global->formsStack)["z"]->getControl() /* line 10 */;
		echo "\n";
		array_pop($this->global->formsStack);
		$formContainer = end($this->global->formsStack);
		echo Nette\Bridges\FormsLatte\Runtime::renderFormEnd(array_pop($this->global->formsStack));
		echo "\n";
		return get_defined_vars();
	}


	public function prepare(): void
	{
		extract($this->params);
		Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
		
	}

}
