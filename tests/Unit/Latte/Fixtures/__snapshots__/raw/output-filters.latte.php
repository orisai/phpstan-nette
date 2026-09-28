<?php

use Latte\Runtime as LR;

final class LatteTpl_fixtures_output_filters_latte_903857f4 extends Latte\Runtime\Template
{

	public function main(): array
	{
		extract($this->params);
		echo "\n";
		echo LR\Filters::escapeHtmlText($x) /* line 5 */;
		echo "\n";
		echo LR\Filters::escapeHtmlText(1 + 2) /* line 6 */;
		echo "\n";
		echo LR\Filters::escapeHtmlText(($this->filters->upper)($x)) /* line 7 */;
		echo "\n";
		echo LR\Filters::escapeHtmlText(($this->filters->truncate)($x, 10)) /* line 8 */;
		echo "\n";
		echo LR\Filters::escapeHtmlText(($this->filters->date)($created, 'j.n.Y')) /* line 9 */;
		echo "\n";
		echo $x /* line 10 */;
		echo "\n";
		echo LR\Filters::escapeHtmlText(($this->filters->truncate)(($this->filters->upper)($x), 10)) /* line 11 */;
		echo "\n";
		ob_start(function () {}) /* line 12 */;
		try {
			echo '	';
			echo LR\Filters::escapeHtmlText($x) /* line 13 */;
			echo "\n";
		} finally {
			$ʟ_tmp = ob_get_length() ? new LR\Html(ob_get_clean()) : ob_get_clean();
		}
		$ʟ_fi = new LR\FilterInfo('html');
		$trimmed = $this->filters->filterContent('trim', $ʟ_fi, $ʟ_tmp);
		echo LR\Filters::escapeHtmlText(($this->filters->translate)('text')) /* line 15 */;
		echo "\n";
		$ʟ_fi = new LR\FilterInfo('html');
		echo $this->filters->filterContent("translate", $ʟ_fi, 'greeting') /* line 16 */;
		echo "\n";
		return get_defined_vars();
	}


	public function prepare(): void
	{
		extract($this->params);
		Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
		
	}

}
