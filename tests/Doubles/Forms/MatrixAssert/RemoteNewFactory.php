<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert;

use Nette\Application\UI\Control;
use Nette\Application\UI\Form;
use function OriPhpstan\Nette\Forms\Testing\assertComponent;
use function OriPhpstan\Nette\Forms\Testing\assertFormValues;

class RemoteNewFactoryForm extends Form
{

	public function __construct()
	{
		parent::__construct();

		$this->addText('alpha');
		$this->addText('beta')
			->setRequired();
	}

}

class RemoteNewFactory
{

	public function build(): RemoteNewFactoryForm
	{
		return new RemoteNewFactoryForm();
	}

}

final class RemoteNewFactoryControl extends Control
{

	private RemoteNewFactory $factory;

	public function __construct(RemoteNewFactory $factory)
	{
		$this->factory = $factory;
	}

	protected function createComponentEditor(): RemoteNewFactoryForm
	{
		$form = $this->factory->build();
		$form->onSuccess[] = [$this, 'onEditorSuccess'];

		return $form;
	}

	public function onEditorSuccess(RemoteNewFactoryForm $form): void
	{
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert\RemoteNewFactoryForm{
			  alpha: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  beta: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, non-empty-string>,
			}
			OUTPUT);
		assertFormValues($form->getValues(), 'Nette\Utils\ArrayHash{alpha: string, beta: non-empty-string}');
	}

	public function readOffset(): void
	{
		// A bare $this['editor'] read is not validation-narrowed, so the required beta control
		// reads its un-narrowed string type — unlike the validated onSuccess handler above.
		assertComponent($this['editor'], <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert\RemoteNewFactoryForm{
			  alpha: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  beta: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT);
	}

}
