<?php

use Latte\Runtime as LR;

final class LatteTpl_fixtures_parameters_latte_031c1534 extends Latte\Runtime\Template
{

	public function main(): array
	{
		$name = $this->params[0] ?? $this->params['name'] ?? null;
		$age = $this->params[1] ?? $this->params['age'] ??  18;
		echo '
Hello ';
		echo LR\Filters::escapeHtmlText($name) /* line 3 */;
		echo ', age ';
		echo LR\Filters::escapeHtmlText($age) /* line 3 */;
		echo "\n";
		return get_defined_vars();
	}


	public function prepare(): void
	{
		$name = $this->params[0] ?? $this->params['name'] ?? null;
		$age = $this->params[1] ?? $this->params['age'] ??  18;
		Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
		
	}

}
