<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Nette\Forms\Container;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function PHPStan\dumpType;

final class MOffsetWrittenFactoryContainerPhone extends Container
{

	public function __construct()
	{
		$this->addText('number');
	}

}

interface MOffsetWrittenFactoryContainerFactory
{

	public function create(bool $required): MOffsetWrittenFactoryContainerPhone;

}

final class MOffsetWrittenFactoryContainer extends Control
{

	private MOffsetWrittenFactoryContainerFactory $phoneFactory;

	public function __construct(MOffsetWrittenFactoryContainerFactory $phoneFactory)
	{
		$this->phoneFactory = $phoneFactory;
	}

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('own');
		$form['phone'] = $this->phoneFactory->create(true);
		$form['card'] = new MOffsetWrittenFactoryContainerPhone();

		return $form;
	}

	public function go(): void
	{
		$form = $this['form'];
		dumpType($form['phone']); // => Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\MOffsetWrittenFactoryContainerPhone{number: string, …+unknown(container_reference)}
		dumpType($form['phone']['number']); // => Nette\Forms\Controls\TextInput
		dumpType($form['card']); // => Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\MOffsetWrittenFactoryContainerPhone{number: string}
		dumpType($form->getValues('array')); // => array{own: string, phone: array{number: string, ...<string, mixed>}, card: array{number: string}}
		$form['card']['nope']->setRequired(); // !! Form component 'nope' does not exist.
	}

}
