<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm;
use Nette\Application\UI\Control;
use Nette\Forms\Controls\TextInput;
use function PHPStan\dumpType;

class MConstructorVarConstConcatNamesType extends RawForm
{

	private const FIELD_TITLE = 'title';

	public function __construct()
	{
		parent::__construct();

		$field = 'note';
		$this->addTextArea($field)->setRequired();

		$this->addText(self::FIELD_TITLE);

		$prefix = 'meta_';
		$this->addHidden($prefix . 'id');

		$this['scenario'] = new TextInput('Scenario');
	}

}

final class MConstructorVarConstConcatNames extends Control
{

	protected function createComponentForm(): MConstructorVarConstConcatNamesType
	{
		$form = new MConstructorVarConstConcatNamesType();
		$form->onSuccess[] = [$this, 'process'];

		return $form;
	}

	public function process(MConstructorVarConstConcatNamesType $form): void
	{
		dumpType($form->getValues()); // => Nette\Utils\ArrayHash{note: non-empty-string, title: string, meta_id: string|null, scenario: string}
	}

}
