<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

/**
 * A form subclassed to build ONE specific form, which is the shape the adds annotation deliberately
 * does not cover: the build method registers several controls under literal names, some of them
 * conditionally, so no tag could summarise it. The build method is private — dispatch cannot be
 * overridden, so the body the constructor reaches is the body this class declares.
 */
final class SpecificPrivateBuildForm extends SpecificProjectBaseForm
{

	public function __construct(bool $withPhone)
	{
		parent::__construct();
		$this->buildContactSection($withPhone);
	}

	private function buildContactSection(bool $withPhone): void
	{
		$this->addText('email');

		if ($withPhone) {
			$this->addText('phone');
		}

		$this->addTextArea('note');
	}

}
