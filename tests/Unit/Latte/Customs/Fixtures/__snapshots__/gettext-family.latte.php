<?php

use Latte\Runtime as LR;

final class LatteTpl_fixtures_gettext_family_latte_aad39d57 extends Latte\Runtime\Template
{

	public function main(): array
	{
		extract($this->params);
		echo LR\Filters::escapeHtmlText(gettext('Hello'));
		echo "\n";
		echo LR\Filters::escapeHtmlText(gettext('Hi there'));
		echo "\n";
		echo LR\Filters::escapeHtmlText(ngettext('one item', 'one item', 'many items'));
		echo "\n";
		echo LR\Filters::escapeHtmlText(dgettext('domain', 'Domain text'));
		echo "\n";
		echo LR\Filters::escapeHtmlText(dngettext('domain', 'one item', 'one item', 'many items'));
		echo "\n";
		return get_defined_vars();
	}


	public function prepare(): void
	{
		extract($this->params);
		Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
		
	}

}
