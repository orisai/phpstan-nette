<?php

use Latte\Runtime as LR;

final class LatteTpl_fixtures_parameters_blocks_latte_3e63c928 extends Latte\Runtime\Template
{
	protected const BLOCKS = [
		['b' => 'blockB'],
	];


	public function main(): array
	{
		$p = $this->params[0] ?? $this->params['p'] ?? null;
		if ($this->getParentName()) {
			return get_defined_vars();
		}
		$this->renderBlock('b', get_defined_vars()) /* line 2 */;
		echo "\n";
		return get_defined_vars();
	}


	public function prepare(): void
	{
		$p = $this->params[0] ?? $this->params['p'] ?? null;
		Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
		
	}


	/** {block b} on line 2 */
	public function blockB(array $ʟ_args): void
	{
		extract($this->params);
		extract($ʟ_args);
		unset($ʟ_args);
		echo LR\Filters::escapeHtmlText($p) /* line 2 */;
		
	}

}
