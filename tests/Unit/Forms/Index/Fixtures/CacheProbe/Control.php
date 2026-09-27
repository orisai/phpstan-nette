<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures\CacheProbe;

class RegisteringControl
{

	private ProbeFactory $factory;

	public function __construct(ProbeFactory $factory)
	{
		$this->factory = $factory;
	}

	public function createComponentForm(): ProbeForm
	{
		$form = $this->factory->build();
		$form->addContainer('sub');
		$form->onSuccess[] = [$this, 'formSucceeded'];

		return $form;
	}

	public function formSucceeded(ProbeForm $form): void
	{
	}

}
