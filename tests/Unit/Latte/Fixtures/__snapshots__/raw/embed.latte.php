<?php

use Latte\Runtime as LR;

final class LatteTpl_fixtures_embed_latte_1475771c extends Latte\Runtime\Template
{
	protected const BLOCKS = [
		['sub' => 'blockSub'],
		['content' => 'blockContent'],
	];


	public function main(): array
	{
		extract($this->params);
		echo "\n";
		if ($this->getParentName()) {
			return get_defined_vars();
		}
		$this->renderBlock('sub', get_defined_vars()) /* line 3 */;
		echo '

';
		$this->enterBlockLayer(1, get_defined_vars()) /* line 5 */;
		if (false) {
			$this->renderBlock('content', get_defined_vars()) /* line 6 */;
			echo "\n";
		}
		try {
			$this->createTemplate('blocks-snippets.latte', ['mode' => $mode], "embed")->renderToContentType('html') /* line 5 */;
		} finally {
			$this->leaveBlockLayer();
		}
		echo '

';
		$this->enterBlockLayer(2, get_defined_vars()) /* line 11 */;
		if (false) {
		}
		$this->copyBlockLayer();
		try {
			$this->renderBlock('sub', [], 'html') /* line 11 */;
		} finally {
			$this->leaveBlockLayer();
		}
		echo "\n";
		return get_defined_vars();
	}


	public function prepare(): void
	{
		extract($this->params);
		Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
		
	}


	/** {block sub} on line 3 */
	public function blockSub(array $ʟ_args): void
	{
		echo 'embedded sub block';
	}


	/** {block content} on line 6 */
	public function blockContent(array $ʟ_args): void
	{
		extract(end($this->varStack));
		extract($ʟ_args);
		unset($ʟ_args);
		echo '		override content ';
		echo LR\Filters::escapeHtmlText($mode) /* line 7 */;
		echo "\n";
	}

}
