<?php

use Latte\Runtime as LR;

final class LatteTpl_fixtures_blocks_snippets_latte_6640b14a extends Latte\Runtime\Template
{
	protected const BLOCKS = [
		0 => ['content' => 'blockContent', 'typed' => 'blockTyped'],
		'snippet' => ['s1' => 'blockS1', 's2' => 'blockS2', 'area1' => 'blockArea1', 'inner' => 'blockInner'],
	];


	public function main(): array
	{
		extract($this->params);
		echo "\n";
		if ($this->getParentName()) {
			return get_defined_vars();
		}
		$this->renderBlock('content', get_defined_vars()) /* line 3 */;
		echo '


';
		$this->renderBlock('content', get_defined_vars(), 'html') /* line 11 */;
		echo "\n";
		$this->renderBlock('typed', ['value'] + [], 'html') /* line 13 */;
		echo '
<div';
		echo ' id="' . htmlspecialchars($this->global->snippetDriver->getHtmlId('s1')) . '"';
		echo '>';
		$this->renderBlock('s1', [], null, 'snippet');
		echo '</div>

<div id="';
		echo htmlspecialchars($this->global->snippetDriver->getHtmlId('s2'));
		echo '">';
		$this->renderBlock('s2', [], null, 'snippet') /* line 17 */;
		echo '</div>

';
		$this->renderBlock('area1', [], null, 'snippet') /* line 21 */;
		echo "\n";
		return get_defined_vars();
	}


	public function prepare(): void
	{
		extract($this->params);
		Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
		
	}


	/** {block content} on line 3 */
	public function blockContent(array $ʟ_args): void
	{
		extract($this->params);
		extract($ʟ_args);
		unset($ʟ_args);
		echo '	block body ';
		echo LR\Filters::escapeHtmlText($x) /* line 4 */;
		echo "\n";
	}


	/** {define typed, string $p} on line 7 */
	public function blockTyped(array $ʟ_args): void
	{
		extract($this->params);
		$p = $ʟ_args[0] ?? $ʟ_args['p'] ?? null;
		unset($ʟ_args);
		echo '	typed body ';
		echo LR\Filters::escapeHtmlText($p) /* line 8 */;
		echo "\n";
	}


	/** {snippet s1} on line 15 */
	public function blockS1(array $ʟ_args): void
	{
		extract($this->params);
		extract($ʟ_args);
		unset($ʟ_args);
		$this->global->snippetDriver->enter("s1", 'static');
		try {
			echo 'snippet content';
		} finally {
			$this->global->snippetDriver->leave();
		}
		
	}


	/** {snippet s2} on line 17 */
	public function blockS2(array $ʟ_args): void
	{
		extract($this->params);
		extract($ʟ_args);
		unset($ʟ_args);
		$this->global->snippetDriver->enter("s2", 'static');
		try {
			echo '	explicit snippet
';
		} finally {
			$this->global->snippetDriver->leave();
		}
		
	}


	/** {snippetArea area1} on line 21 */
	public function blockArea1(array $ʟ_args): void
	{
		extract($this->params);
		extract($ʟ_args);
		unset($ʟ_args);
		$this->global->snippetDriver->enter('area1', 'area');
		try {
			echo '	<div id="';
			echo htmlspecialchars($this->global->snippetDriver->getHtmlId('inner'));
			echo '">';
			$this->renderBlock('inner', [], null, 'snippet') /* line 22 */;
			echo '</div>
';
		} finally {
			$this->global->snippetDriver->leave();
		}
		
	}


	/** {snippet inner} on line 22 */
	public function blockInner(array $ʟ_args): void
	{
		extract($this->params);
		extract($ʟ_args);
		unset($ʟ_args);
		$this->global->snippetDriver->enter("inner", 'static');
		try {
			echo 'area snippet';
		} finally {
			$this->global->snippetDriver->leave();
		}
		
	}

}
