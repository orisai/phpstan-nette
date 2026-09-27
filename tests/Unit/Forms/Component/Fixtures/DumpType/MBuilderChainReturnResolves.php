<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class MBuilderChainReturnResolvesBuilder
{

	private string $label = '';

	public function withLabel(string $label): self
	{
		$this->label = $label;

		return $this;
	}

	public function create(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('builderField');

		return $form;
	}

}

final class MBuilderChainReturnResolves extends Control
{

	private MBuilderChainReturnResolvesBuilder $builder;

	protected function createComponentForm(): ApplicationForm
	{
		return $this->builder->withLabel('foo')->create();
	}

	public function go(): void
	{
		dumpType($this['form']['builderField']); // => Nette\Forms\Controls\TextInput
	}

}
