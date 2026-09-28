<?php

use Latte\Runtime as LR;

final class LatteTpl_fixtures_n_attributes_latte_366f42ea extends Latte\Runtime\Template
{

	public function main(): array
	{
		extract($this->params);
		echo "\n";
		if ($cond) /* line 6 */ {
			echo '<div>shown</div>
';
		}
		echo '
<ul>
';
		$iterations = 0;
		foreach ($items as $item) /* line 9 */ {
			echo '	<li>';
			echo LR\Filters::escapeHtmlText($item) /* line 9 */;
			echo '</li>
';
			$iterations++;
		}
		echo '</ul>

<div>';
		$iterations = 0;
		foreach ($items as $item) /* line 12 */ {
			echo LR\Filters::escapeHtmlText($item) /* line 12 */;
			$iterations++;
		}
		echo '</div>

';
		if ($ʟ_if[0] = ($cond)) /* line 14 */ {
			echo '<div>';
		}
		echo 'tag-if content';
		if ($ʟ_if[0]) /* line 14 */ {
			echo '</div>
';
		}
		echo '
<div';
		echo ($ʟ_tmp = array_filter([$cond ? 'active' : null, $activeClass, 'base'])) ? ' class="' . LR\Filters::escapeHtmlAttr(implode(" ", array_unique($ʟ_tmp))) . '"' : "" /* line 16 */;
		echo '>class</div>

<div';
		echo ($ʟ_tmp = array_filter([$cond ? 'form-group has-error' : 'form-group'])) ? ' class="' . LR\Filters::escapeHtmlAttr(implode(" ", array_unique($ʟ_tmp))) . '"' : "" /* line 18 */;
		echo '>always-truthy class</div>

<div';
		$ʟ_tmp = ['data-x' => $cond ? 1 : null];
		echo LR\Filters::htmlAttributes(isset($ʟ_tmp[0]) && is_array($ʟ_tmp[0]) ? $ʟ_tmp[0] : $ʟ_tmp) /* line 20 */;
		echo '></div>

';
		$ʟ_tag[0] = ($tagName) ?? 'div';
		Latte\Runtime\Filters::checkTagSwitch('div', $ʟ_tag[0]);
		echo '<';
		echo $ʟ_tag[0];
		echo '>tag</';
		echo $ʟ_tag[0];
		echo '>

';
		ob_start(function () {});
		try {
			echo '<p>';
			ob_start();
			try {
				if ($cond) /* line 24 */ {
					echo 'x';
				}
			} finally {
				$ʟ_ifc[1] = rtrim(ob_get_flush()) === '';
			}
			echo '</p>
';
		} finally {
			if ($ʟ_ifc[1] ?? null) {
				ob_end_clean();
			} else {
				echo ob_get_clean();
			}
		}
		echo '
<script';
		echo $this->global->uiNonce ? " nonce=\"{$this->global->uiNonce}\"" : "";
		echo '>window.x=1;</script>
';
		return get_defined_vars();
	}


	public function prepare(): void
	{
		extract($this->params);
		if (!$this->getReferringTemplate() || $this->getReferenceType() === "extends") {
			foreach (array_intersect_key(['item' => '9, 12'], $this->params) as $ʟ_v => $ʟ_l) {
				trigger_error("Variable \$$ʟ_v overwritten in foreach on line $ʟ_l");
			}
		}
		Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
		
	}

}
