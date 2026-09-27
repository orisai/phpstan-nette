<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\RealAnalyse;

use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\Support\FmClosedAndOpenContainerControl;

/**
 * The walk channel's counterpart to UnknownNameOnClosedShape.php: this file builds no form of its
 * own, so ContainerModel answers every offset below, exactly as a template's $control['form'][…] is
 * answered. Each access is a member access ON the absent name, which is what makes the report count
 * visible - an IComponent here reported the same mistake a second time as an undefined method,
 * property or offset on top of the rule's own message.
 */
final class UnknownNameOnClosedShapeThroughWalk
{

	public function go(FmClosedAndOpenContainerControl $control): void
	{
		$control['form']['closed']['nope']->getValue();
		$control['form']['closed']['nope']->control;
		$control['form']['closed']['nope']['deeper'];
	}

}
