<?php

use Latte\Runtime as LR;

final class LatteTpl_fixtures_ui_macros_latte_1e9a68ee extends Latte\Runtime\Template
{

	public function main(): array
	{
		extract($this->params);
		echo "\n";
		/* line 3 */ $_tmp = $this->global->uiControl->getComponent("menu");
		if ($_tmp instanceof Nette\Application\UI\Renderable) $_tmp->redrawControl(null, false);
		$_tmp->render();
		echo "\n";
		/* line 5 */ if (is_object($obj)) $_tmp = $obj;
		else $_tmp = $this->global->uiControl->getComponent($obj);
		if ($_tmp instanceof Nette\Application\UI\Renderable) $_tmp->redrawControl(null, false);
		$_tmp->renderPart([1, 'a' => 2]);
		echo '
<a href="';
		echo LR\Filters::escapeHtmlAttr($this->global->uiControl->link("this")) /* line 7 */;
		echo '">this</a>

<a href="';
		echo LR\Filters::escapeHtmlAttr($this->global->uiPresenter->link("Foo:bar")) /* line 9 */;
		echo '">bar</a>

<a href="';
		echo LR\Filters::escapeHtmlAttr($this->global->uiControl->link("Foo:baz", [1, 'x' => 2])) /* line 11 */;
		echo '">baz</a>
';
		return get_defined_vars();
	}


	public function prepare(): void
	{
		extract($this->params);
		Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
		
	}

}
