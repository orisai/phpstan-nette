<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Support;

final class GapThreeFormUsage
{

	public function build(GapThreeFormFactory $factory): void
	{
		$form = $factory->create();
		$form->addText('name');
	}

}
