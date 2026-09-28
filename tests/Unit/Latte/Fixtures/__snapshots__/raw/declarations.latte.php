<?php

use Latte\Runtime as LR;

final class LatteTpl_fixtures_declarations_latte_101fb50a extends Latte\Runtime\Template
{

	public function main(): array
	{
		extract($this->params);
		echo "\n";
		$x = 1 /* line 4 */;
		$y = 2 /* line 5 */;
		extract(['z' => 3], EXTR_SKIP) /* line 6 */;
		$noop = null /* line 7 */;
		$noop = 1 /* line 8 */;
		echo LR\Filters::escapeHtmlText($label) /* line 9 */;
		echo ' ';
		echo LR\Filters::escapeHtmlText($x) /* line 9 */;
		echo ' ';
		echo LR\Filters::escapeHtmlText($y) /* line 9 */;
		echo ' ';
		echo LR\Filters::escapeHtmlText($z) /* line 9 */;
		echo "\n";
		extract(['flag' => false], EXTR_SKIP) /* line 10 */;
		if ($flag) /* line 11 */ {
			echo 'on';
		}
		echo "\n";
		echo LR\Filters::escapeHtmlText($late) /* line 13 */;
		echo "\n";
		return get_defined_vars();
	}


	public function prepare(): void
	{
		extract($this->params);
		Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
		
	}

}
