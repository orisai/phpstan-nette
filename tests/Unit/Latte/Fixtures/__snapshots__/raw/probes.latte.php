<?php

use Latte\Runtime as LR;

final class LatteTpl_fixtures_probes_latte_4c7d2b2d extends Latte\Runtime\Template
{
	protected const BLOCKS = [
		['wrapper' => 'blockWrapper'],
	];


	public function main(): array
	{
		extract($this->params);
		echo "\n";
		$this->addBlock($ʟ_nm = $name, 'html', [[$this, 'blockName']], null);
		$this->renderBlock($ʟ_nm, get_defined_vars());
		echo '

';
		if ($this->getParentName()) {
			return get_defined_vars();
		}
		$this->renderBlock('wrapper', get_defined_vars()) /* line 7 */;
		echo '

';
		ob_start(function () {}) /* line 11 */;
		try {
			echo 'anonymous filtered ';
			echo LR\Filters::escapeHtmlText($mode) /* line 11 */;
		} finally {
			$ʟ_fi = new LR\FilterInfo('html');
			echo LR\Filters::convertTo($ʟ_fi, 'html', $this->filters->filterContent('upper', $ʟ_fi, ob_get_clean()));
		}
		echo '

';
		ob_start(function () {});
		try {
			$this->createTemplate('declarations.latte', ['flag' => true], "sandbox")->renderToContentType('html') /* line 13 */;
			echo ob_get_clean();
		} catch (\Throwable $ʟ_e) {
			if (isset($this->global->coreExceptionHandler)) {
				ob_end_clean();
				($this->global->coreExceptionHandler)($ʟ_e, $this);
			} else {
				echo ob_get_clean();
				throw $ʟ_e;
			}
		}
		echo "\n";
		Nette\Forms\Blueprint::latte($this->global->uiControl["myForm"]);
		exit;
		return get_defined_vars();
	}


	public function prepare(): void
	{
		extract($this->params);
		$this->createTemplate('blocks-snippets.latte', $this->params, "import")->render() /* line 1 */;
		Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
		
	}


	/** {block $name} on line 5 */
	public function blockName(array $ʟ_args): void
	{
		extract($ʟ_args);
		unset($ʟ_args);
		echo 'dynamic ';
		echo LR\Filters::escapeHtmlText($name) /* line 5 */;
		
	}


	/** {block wrapper} on line 7 */
	public function blockWrapper(array $ʟ_args): void
	{
		extract($this->params);
		extract($ʟ_args);
		unset($ʟ_args);
		$this->renderBlockParent('wrapper', get_defined_vars()) /* line 8 */;
		
	}

}
