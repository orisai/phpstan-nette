<?php

use Latte\Runtime as LR;

final class LatteTpl_fixtures_ifcurrent_latte_90517d0b extends Latte\Runtime\Template
{

	public function main(): array
	{
		extract($this->params);
		echo "\n";
		if ($this->global->uiPresenter->isLinkCurrent($dest, ['mode' => 'x'])) {
			echo '	';
			echo LR\Filters::escapeHtmlText($label) /* line 5 */;
			echo "\n";
		}
		echo "\n";
		if ($this->global->uiPresenter->getLastCreatedRequestFlag("current")) {
			echo '	';
			echo LR\Filters::escapeHtmlText($label) /* line 9 */;
			echo "\n";
		}
		return get_defined_vars();
	}


	public function prepare(): void
	{
		extract($this->params);
		Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
		
	}

}
