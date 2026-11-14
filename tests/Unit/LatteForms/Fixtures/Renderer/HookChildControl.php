<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

final class HookChildControl extends HookBaseControl
{

	protected function buildHookFields(PairingForm $form): void
	{
		$form->addText('hooked');
	}

}
