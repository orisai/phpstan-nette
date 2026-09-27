<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert;

use Nette\Application\UI\Form;
use function OriPhpstan\Nette\Forms\Testing\assertComponent;

class LocalNewFactoryForm extends Form
{

	public function __construct()
	{
		parent::__construct();

		$this->addText('alpha');
		$this->addText('beta')
			->setRequired();
	}

}

class LocalNewFactory
{

	public function build(): LocalNewFactoryForm
	{
		return new LocalNewFactoryForm();
	}

	public function buildDynamic(): LocalNewFactoryForm
	{
		$className = LocalNewFactoryForm::class;

		return new $className();
	}

}

final class LocalNewFactoryReader
{

	private LocalNewFactory $factory;

	public function __construct(LocalNewFactory $factory)
	{
		$this->factory = $factory;
	}

	public function read(): void
	{
		$form = $this->factory->build();
		assertComponent($form['alpha'], 'Nette\Forms\Controls\TextInput');
		assertComponent($form['beta'], 'Nette\Forms\Controls\TextInput');
	}

	public function readDynamic(): void
	{
		$form = $this->factory->buildDynamic();
		assertComponent($form['alpha'], 'Nette\ComponentModel\IComponent');
		assertComponent($form['beta'], 'Nette\ComponentModel\IComponent');
	}

}
