<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\RealAnalyse;

use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\Support\FmFormLevelShapeControl;

/**
 * UnknownNameOnClosedShapeThroughWalk.php's form-level sibling. There the shape sat on a CONTAINER
 * one hop in; here it sits on the form itself, which is the receiver every template writes as
 * {var $form = $control['form']} and the one that used to carry no shape at all - so the absence was
 * not reported once, it was not reported ever, and each member access below was reported instead as
 * an undefined method, property or offset on an IComponent. Exactly one report per mistake now, and
 * none of them a cascade message.
 */
final class UnknownNameOnFormLevelShape
{

	public function go(FmFormLevelShapeControl $control): void
	{
		$control['form']['nope']->getValue();
		$control['form']['nope']->control;
		$control['form']['nope']['deeper'];
	}

}
