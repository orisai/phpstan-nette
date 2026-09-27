<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

abstract class MSelfChainBuilderBase
{

	/** @var string */
	protected $lang = '';

	/**
	 * @return $this
	 */
	public function setLang(string $value): self
	{
		$this->lang = $value;

		return $this;
	}

	abstract public function create();

}

final class MSelfChainBuilder extends MSelfChainBuilderBase
{

	public function create(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('profileField');

		return $form;
	}

}

interface MSelfChainBuilderFactory
{

	public function create(): MSelfChainBuilder;

}

final class MSelfChainReturnResolves extends Control
{

	private MSelfChainBuilderFactory $ctrl;

	protected function createComponentForm(): ApplicationForm
	{
		return $this->ctrl->create()->setLang('cs')->create();
	}

	public function go(): void
	{
		dumpType($this['form']['profileField']); // => Nette\Forms\Controls\TextInput
	}

}
