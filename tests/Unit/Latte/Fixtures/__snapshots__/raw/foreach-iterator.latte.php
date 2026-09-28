<?php

use Latte\Runtime as LR;

final class LatteTpl_fixtures_foreach_iterator_latte_898f2db1 extends Latte\Runtime\Template
{

	public function main(): array
	{
		extract($this->params);
		echo "\n";
		$iterations = 0;
		foreach ($items as $item) /* line 4 */ {
			echo '	';
			echo LR\Filters::escapeHtmlText($item) /* line 5 */;
			echo "\n";
			$iterations++;
		}
		echo "\n";
		$iterations = 0;
		foreach ($iterator = $ʟ_it = new LR\CachingIterator($items, $ʟ_it ?? null) as $key => $item) /* line 8 */ {
			echo '	';
			if ($iterator->isFirst()) /* line 9 */ {
				echo 'first ';
			}
			echo LR\Filters::escapeHtmlText($item) /* line 9 */;
			if ($iterator->isLast()) /* line 9 */ {
				echo ' last';
			}
			echo "\n";
			$iterations++;
		}
		$iterator = $ʟ_it = $ʟ_it->getParent();
		echo "\n";
		$iterations = 0;
		foreach ($iterator = $ʟ_it = new LR\CachingIterator($groups, $ʟ_it ?? null) as $group) /* line 12 */ {
			$iterations = 0;
			foreach ($iterator = $ʟ_it = new LR\CachingIterator($group, $ʟ_it ?? null) as $item) /* line 13 */ {
				echo '		';
				if ($iterator->isFirst()) /* line 14 */ {
					echo 'head ';
				}
				echo LR\Filters::escapeHtmlText($item) /* line 14 */;
				if ($iterator->isLast()) /* line 14 */ {
					echo ' tail';
				}
				echo "\n";
				$iterations++;
			}
			$iterator = $ʟ_it = $ʟ_it->getParent();
			$iterations++;
		}
		$iterator = $ʟ_it = $ʟ_it->getParent();
		echo "\n";
		$iterations = 0;
		foreach ($iterator = $ʟ_it = new LR\CachingIterator($items, $ʟ_it ?? null) as $item) /* line 18 */ {
			do /* line 19 */ {
				echo '		';
				echo LR\Filters::escapeHtmlText($item) /* line 20 */;
				echo "\n";
				
				if (!$iterator->hasNext() || !($item === $item)) {
					break;
				}
				$iterator->next();
				[, $item] = [$iterator->key(), $iterator->current()];
			}
			while (true);
			echo '	';
			if (!$iterator->isLast()) /* line 22 */ {
				echo ', ';
			}
			echo "\n";
			$iterations++;
		}
		$iterator = $ʟ_it = $ʟ_it->getParent();
		return get_defined_vars();
	}


	public function prepare(): void
	{
		extract($this->params);
		if (!$this->getReferringTemplate() || $this->getReferenceType() === "extends") {
			foreach (array_intersect_key(['item' => '4, 8, 13, 18', 'key' => '8', 'group' => '12'], $this->params) as $ʟ_v => $ʟ_l) {
				trigger_error("Variable \$$ʟ_v overwritten in foreach on line $ʟ_l");
			}
		}
		Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
		
	}

}
