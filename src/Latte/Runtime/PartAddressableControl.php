<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Runtime;

use Nette\Utils\Html;

// What a `{input x:$key}`/`{label x:$key /}` part reference assumes of a control the analysis could
// not resolve: BaseControl declares both getters without the key a choice control accepts.
interface PartAddressableControl
{

	/**
	 * @param mixed $key
	 */
	public function getControlPart($key = null): Html;

	/**
	 * @param mixed $key
	 */
	public function getLabelPart($key = null): Html;

}
