<?php

use Latte\Runtime as LR;

final class LatteTpl_fixtures_control_flow_latte_c4f04f4b extends Latte\Runtime\Template
{

	public function main(): array
	{
		extract($this->params);
		echo "\n";
		if ($show) /* line 7 */ {
			echo '	shown
';
		} elseif ($level > 1) /* line 9 */ {
			echo '	high
';
		} else /* line 11 */ {
			echo '	hidden
';
		}
		echo "\n";
		if (isset($items[0])) /* line 15 */ {
			echo '	first set
';
		}
		echo "\n";
		$ʟ_switch = ($level) /* line 19 */;
		if (false) {
		} elseif (in_array($ʟ_switch, [1], true)) /* line 20 */ {
			echo '		one
';
		} elseif (in_array($ʟ_switch, [2, 3], true)) /* line 22 */ {
			echo '		two or three
';
		} else /* line 24 */ {
			echo '		other
';
		}
		echo "\n";
		$i = 0 /* line 28 */;
		while ($i < 3) /* line 29 */ {
			$i++ /* line 30 */;
		}
		echo "\n";
		for ($j = 0;
		$j < 3;
		$j++) /* line 33 */ {
			echo '	loop ';
			echo LR\Filters::escapeHtmlText($j) /* line 34 */;
			echo "\n";
		}
		echo "\n";
		$ʟ_try[0] = [$ʟ_it ?? null];
		ob_start(function () {});
		try /* line 37 */ {
			echo '	risky
	';
			if ($flag) /* line 39 */ {
				throw new LR\RollbackException;
			}
			echo "\n";
		} catch (Throwable $ʟ_e) {
			ob_end_clean();
			if (!($ʟ_e instanceof LR\RollbackException) && isset($this->global->coreExceptionHandler)) {
				($this->global->coreExceptionHandler)($ʟ_e, $this);
			}
			echo '	caught
';
			ob_start();
		} finally {
			echo ob_get_clean();
			$iterator = $ʟ_it = $ʟ_try[0][0];
		}
		echo "\n";
		if (($ʟ_loc[1] ?? null) !== ($ʟ_tmp = [$level])) {
			$ʟ_loc[1] = $ʟ_tmp;
			echo '	changed to ';
			echo LR\Filters::escapeHtmlText($level) /* line 45 */;
			echo "\n";
		}
		echo "\n";
		$iterations = 0;
		foreach ($iterator = $ʟ_it = new LR\CachingIterator($items, $ʟ_it ?? null) as $item) /* line 48 */ {
			if ($item === null) /* line 49 */ continue;
			if ($item === 'stop') /* line 50 */ break;
			if ($item === 'skip') /* line 51 */ {
				$iterator->skipRound();
				continue;
			};
			echo '	';
			echo LR\Filters::escapeHtmlText($item) /* line 52 */;
			echo "\n";
			$iterations++;
		}
		$iterator = $ʟ_it = $ʟ_it->getParent();
		echo "\n";
		ob_start(function () {}) /* line 55 */;
		try {
			echo '	captured block
';
			
		} finally {
			$ʟ_ifA = ob_get_clean();
		}
		if ($showCaptured) /* line 55 */ {
			echo $ʟ_ifA;
		}
		echo "\n";
		return get_defined_vars();
	}


	public function prepare(): void
	{
		extract($this->params);
		if (!$this->getReferringTemplate() || $this->getReferenceType() === "extends") {
			foreach (array_intersect_key(['item' => '48'], $this->params) as $ʟ_v => $ʟ_l) {
				trigger_error("Variable \$$ʟ_v overwritten in foreach on line $ʟ_l");
			}
		}
		Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
		
	}

}
