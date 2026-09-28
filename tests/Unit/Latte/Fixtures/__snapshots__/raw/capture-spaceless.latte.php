<?php

use Latte\Runtime as LR;

final class LatteTpl_fixtures_capture_spaceless_latte_520212b0 extends Latte\Runtime\Template
{

	public function main(): array
	{
		extract($this->params);
		echo "\n";
		ob_start(function () {}) /* line 3 */;
		try {
			echo '	captured ';
			echo LR\Filters::escapeHtmlText($x) /* line 4 */;
			echo "\n";
		} finally {
			$ʟ_tmp = ob_get_length() ? new LR\Html(ob_get_clean()) : ob_get_clean();
		}
		$ʟ_fi = new LR\FilterInfo('html');
		$v = $ʟ_tmp;
		echo "\n";
		ob_start('Latte\Runtime\Filters::spacelessHtmlHandler', 4096) /* line 7 */;
		try {
			echo '	<div>
		<span>';
			echo LR\Filters::escapeHtmlText($x) /* line 9 */;
			echo '</span>
	</div>
';
		} finally {
			ob_end_flush();
		}
		echo '
{literal brace}

';
		Tracy\Debugger::barDump(($x), '$x') /* line 15 */;
		return get_defined_vars();
	}


	public function prepare(): void
	{
		extract($this->params);
		Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
		
	}

}
