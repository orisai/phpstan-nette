<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

final class NestedOutsideMutator
{

	public function createComponentNestedHost(): NestedMutatedRenderer
	{
		return new NestedMutatedRenderer();
	}

	public function actionLate(): void
	{
		$this['nestedHost']['nestedForm']['shipping']->addText('late');
	}

}
