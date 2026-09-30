<?php

use Latte\Runtime as LR;

final class LatteTpl_fixtures_extends_latte_4e7b036e extends Latte\Runtime\Template
{
	protected const BLOCKS = [
		['title' => 'blockTitle'],
	];


	public function main(): array
	{
		extract($this->params);
		echo "\n";
		if ($this->getParentName()) {
			return get_defined_vars();
		}
		$this->renderBlock('title', get_defined_vars()) /* line 4 */;
		echo "\n";
		return get_defined_vars();
	}


	public function prepare(): void
	{
		extract($this->params);
		$this->parentName = 'parent.latte';
		Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
		
	}


	/** {block title} on line 4 */
	public function blockTitle(array $ʟ_args): void
	{
		extract($this->params);
		extract($ʟ_args);
		unset($ʟ_args);
		echo LR\Filters::escapeHtmlText($title) /* line 4 */;
		
	}

}
