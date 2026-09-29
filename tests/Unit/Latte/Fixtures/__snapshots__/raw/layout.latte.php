<?php

use Latte\Runtime as LR;

final class LatteTpl_fixtures_layout_latte_c2b2d2f9 extends Latte\Runtime\Template
{
	protected const BLOCKS = [
		['title' => 'blockTitle', 'content' => 'blockContent'],
	];


	public function main(): array
	{
		extract($this->params);
		$subtitle = 'sub' /* line 5 */;
		echo "\n";
		if ($this->getParentName()) {
			return get_defined_vars();
		}
		$this->renderBlock('title', get_defined_vars()) /* line 7 */;
		echo '

';
		$this->renderBlock('content', get_defined_vars()) /* line 9 */;
		echo "\n";
		return get_defined_vars();
	}


	public function prepare(): void
	{
		extract($this->params);
		$this->parentName = 'parent.latte';
		$this->createTemplate('blocks-snippets.latte', $this->params, "import")->render() /* line 3 */;
		Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
		
	}


	/** {block title} on line 7 */
	public function blockTitle(array $ʟ_args): void
	{
		extract($this->params);
		extract($ʟ_args);
		unset($ʟ_args);
		echo LR\Filters::escapeHtmlText($title) /* line 7 */;
		echo ' ';
		echo LR\Filters::escapeHtmlText($subtitle) /* line 7 */;
		
	}


	/** {block content} on line 9 */
	public function blockContent(array $ʟ_args): void
	{
		extract($this->params);
		extract($ʟ_args);
		unset($ʟ_args);
		$this->renderBlock('typed', ['value'] + [], 'html') /* line 10 */;
		
	}

}
