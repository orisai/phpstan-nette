<?php

use Latte\Runtime as LR;

final class LatteTpl_fixtures_filtered_block_latte_7f2fe8d9 extends Latte\Runtime\Template
{

	public function main(): array
	{
		extract($this->params);
		if ($this->hasBlock("title")) /* line 1 */ {
			ob_start(function () {}) /* line 1 */;
			try {
				$this->renderBlock('title', [], 'html') /* line 1 */;
			} finally {
				$ʟ_fi = new LR\FilterInfo('html');
				echo LR\Filters::convertTo($ʟ_fi, 'html', $this->filters->filterContent('trim', $ʟ_fi, $this->filters->filterContent('striptags', $ʟ_fi, ob_get_clean())));
			}
		}
		echo "\n";
		return get_defined_vars();
	}


	public function prepare(): void
	{
		extract($this->params);
		Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
		
	}

}
