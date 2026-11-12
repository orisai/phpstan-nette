<?php

use Latte\Runtime as LR;

final class LatteTpl_fixtures_statement_containers_latte_bfe136fb extends Latte\Runtime\Template
{
	protected const BLOCKS = [
		0 => ['sub' => 'blockSub'],
		'snippet' => ['s1' => 'blockS1'],
	];


	public function main(): array
	{
		extract($this->params);
		echo "\n";
		if ($this->getParentName()) {
			return get_defined_vars();
		}
		$this->renderBlock('sub', get_defined_vars()) /* line 7 */;
		echo '

';
		if ($cond) /* line 9 */ {
			echo '	plain
';
		} elseif ($level > 1) /* line 11 */ {
			ob_start(function () {});
			try {
				echo '	<p>';
				ob_start();
				try {
					echo LR\Filters::escapeHtmlText($x) /* line 12 */;
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
			echo '	<div';
			$ʟ_tmp = ['data-x' => $level];
			echo LR\Filters::htmlAttributes(isset($ʟ_tmp[0]) && is_array($ʟ_tmp[0]) ? $ʟ_tmp[0] : $ʟ_tmp) /* line 13 */;
			echo '></div>
';
			$ʟ_tag[0] = ($tagName) ?? 'div';
			Latte\Runtime\Filters::checkTagSwitch('div', $ʟ_tag[0]);
			echo '	<';
			echo $ʟ_tag[0];
			echo '>tag</';
			echo $ʟ_tag[0];
			echo '>
	';
			ob_start(function () {}) /* line 15 */;
			try {
				echo LR\Filters::escapeHtmlText($x) /* line 15 */;
			} finally {
				$ʟ_tmp = ob_get_length() ? new LR\Html(ob_get_clean()) : ob_get_clean();
			}
			$ʟ_fi = new LR\FilterInfo('html');
			$elseifCaptured = $ʟ_tmp;
			echo "\n";
			/* line 16 */ $_tmp = $this->global->uiControl->getComponent("menu");
			if ($_tmp instanceof Nette\Application\UI\Renderable) $_tmp->redrawControl(null, false);
			$_tmp->render();
		} else /* line 17 */ {
			ob_start(function () {});
			try {
				echo '	<p>';
				ob_start();
				try {
					echo LR\Filters::escapeHtmlText($x) /* line 18 */;
				} finally {
					$ʟ_ifc[2] = rtrim(ob_get_flush()) === '';
				}
				echo '</p>
';
			} finally {
				if ($ʟ_ifc[2] ?? null) {
					ob_end_clean();
				} else {
					echo ob_get_clean();
				}
			}
			echo '	';
			$ʟ_try[0] = [$ʟ_it ?? null];
			ob_start(function () {});
			try /* line 19 */ {
				echo 'risky';
			} catch (Throwable $ʟ_e) {
				ob_end_clean();
				if (!($ʟ_e instanceof LR\RollbackException) && isset($this->global->coreExceptionHandler)) {
					($this->global->coreExceptionHandler)($ʟ_e, $this);
				}
				
				ob_start();
			} finally {
				echo ob_get_clean();
				$iterator = $ʟ_it = $ʟ_try[0][0];
			}
			echo '
	';
			$this->enterBlockLayer(1, get_defined_vars()) /* line 20 */;
			if (false) {
			}
			$this->copyBlockLayer();
			try {
				$this->renderBlock('sub', [], 'html') /* line 20 */;
			} finally {
				$this->leaveBlockLayer();
			}
			echo '
	<div';
			echo ' id="' . htmlspecialchars($this->global->snippetDriver->getHtmlId('s1')) . '"';
			echo '>';
			$this->renderBlock('s1', [], null, 'snippet');
			echo '</div>
	';
			$iterations = 0;
			foreach ($items as $item) /* line 22 */ {
				echo LR\Filters::escapeHtmlText($item) /* line 22 */;
				$iterations++;
			}
			echo "\n";
		}
		echo "\n";
		$ʟ_switch = ($level) /* line 25 */;
		if (false) {
		} elseif (in_array($ʟ_switch, [1], true)) /* line 26 */ {
			ob_start(function () {});
			try {
				echo '		<p>';
				ob_start();
				try {
					echo LR\Filters::escapeHtmlText($x) /* line 27 */;
				} finally {
					$ʟ_ifc[3] = rtrim(ob_get_flush()) === '';
				}
				echo '</p>
';
			} finally {
				if ($ʟ_ifc[3] ?? null) {
					ob_end_clean();
				} else {
					echo ob_get_clean();
				}
			}
			echo '		';
			ob_start(function () {}) /* line 28 */;
			try {
				echo LR\Filters::escapeHtmlText($x) /* line 28 */;
			} finally {
				$ʟ_tmp = ob_get_length() ? new LR\Html(ob_get_clean()) : ob_get_clean();
			}
			$ʟ_fi = new LR\FilterInfo('html');
			$caseCaptured = $ʟ_tmp;
			echo "\n";
			/* line 29 */ $_tmp = $this->global->uiControl->getComponent("menu");
			if ($_tmp instanceof Nette\Application\UI\Renderable) $_tmp->redrawControl(null, false);
			$_tmp->render();
		}
		echo "\n";
		$i = 0 /* line 32 */;
		while ($i < 3) /* line 33 */ {
			ob_start(function () {});
			try {
				echo '	<p>';
				ob_start();
				try {
					echo LR\Filters::escapeHtmlText($x) /* line 34 */;
				} finally {
					$ʟ_ifc[4] = rtrim(ob_get_flush()) === '';
				}
				echo '</p>
';
			} finally {
				if ($ʟ_ifc[4] ?? null) {
					ob_end_clean();
				} else {
					echo ob_get_clean();
				}
			}
			/* line 35 */ $_tmp = $this->global->uiControl->getComponent("menu");
			if ($_tmp instanceof Nette\Application\UI\Renderable) $_tmp->redrawControl(null, false);
			$_tmp->render();
			$i++ /* line 36 */;
		}
		echo "\n";
		for ($j = 0;
		$j < 3;
		$j++) /* line 39 */ {
			echo '	<div';
			$ʟ_tmp = ['data-j' => $j];
			echo LR\Filters::htmlAttributes(isset($ʟ_tmp[0]) && is_array($ʟ_tmp[0]) ? $ʟ_tmp[0] : $ʟ_tmp) /* line 40 */;
			echo '></div>
	';
			$ʟ_try[0] = [$ʟ_it ?? null];
			ob_start(function () {});
			try /* line 41 */ {
				echo 'in for';
			} catch (Throwable $ʟ_e) {
				ob_end_clean();
				if (!($ʟ_e instanceof LR\RollbackException) && isset($this->global->coreExceptionHandler)) {
					($this->global->coreExceptionHandler)($ʟ_e, $this);
				}
				
				ob_start();
			} finally {
				echo ob_get_clean();
				$iterator = $ʟ_it = $ʟ_try[0][0];
			}
			echo '
	';
			$iterations = 0;
			foreach ($items as $item) /* line 42 */ {
				echo LR\Filters::escapeHtmlText($item) /* line 42 */;
				$iterations++;
			}
			echo "\n";
		}
		echo "\n";
		$ʟ_try[0] = [$ʟ_it ?? null];
		ob_start(function () {});
		try /* line 45 */ {
			ob_start(function () {});
			try {
				echo '	<p>';
				ob_start();
				try {
					echo LR\Filters::escapeHtmlText($x) /* line 46 */;
				} finally {
					$ʟ_ifc[5] = rtrim(ob_get_flush()) === '';
				}
				echo '</p>
';
			} finally {
				if ($ʟ_ifc[5] ?? null) {
					ob_end_clean();
				} else {
					echo ob_get_clean();
				}
			}
			echo '	';
			ob_start(function () {}) /* line 47 */;
			try {
				echo LR\Filters::escapeHtmlText($x) /* line 47 */;
			} finally {
				$ʟ_tmp = ob_get_length() ? new LR\Html(ob_get_clean()) : ob_get_clean();
			}
			$ʟ_fi = new LR\FilterInfo('html');
			$tryCaptured = $ʟ_tmp;
			echo "\n";
		} catch (Throwable $ʟ_e) {
			ob_end_clean();
			if (!($ʟ_e instanceof LR\RollbackException) && isset($this->global->coreExceptionHandler)) {
				($this->global->coreExceptionHandler)($ʟ_e, $this);
			}
			
			$ʟ_tag[0] = ($tagName) ?? 'div';
			Latte\Runtime\Filters::checkTagSwitch('div', $ʟ_tag[0]);
			echo '	<';
			echo $ʟ_tag[0];
			echo '>caught</';
			echo $ʟ_tag[0];
			echo '>
';
			ob_start();
		} finally {
			echo ob_get_clean();
			$iterator = $ʟ_it = $ʟ_try[0][0];
		}
		return get_defined_vars();
	}


	public function prepare(): void
	{
		extract($this->params);
		if (!$this->getReferringTemplate() || $this->getReferenceType() === "extends") {
			foreach (array_intersect_key(['item' => '22, 42'], $this->params) as $ʟ_v => $ʟ_l) {
				trigger_error("Variable \$$ʟ_v overwritten in foreach on line $ʟ_l");
			}
		}
		Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
		
	}


	/** {block sub} on line 7 */
	public function blockSub(array $ʟ_args): void
	{
		echo 'embedded sub block';
	}


	/** {snippet s1} on line 21 */
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

}
