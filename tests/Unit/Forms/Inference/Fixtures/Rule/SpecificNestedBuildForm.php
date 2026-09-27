<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

/**
 * The build method is reached through a second hop — the constructor calls one in-class method which
 * calls another. Neither hop is spelled add*.
 */
final class SpecificNestedBuildForm extends SpecificProjectBaseForm
{

	public function __construct()
	{
		parent::__construct();
		$this->buildAll();
	}

	private function buildAll(): void
	{
		$this->buildInner();
		$this->addText('outer');
	}

	private function buildInner(): void
	{
		$this->addText('inner');
	}

}
