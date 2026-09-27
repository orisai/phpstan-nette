<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Nette\Application\UI\Control;
use Nette\Forms\Container;
use function PHPStan\dumpType;

final class MAnnotationPhpstanAssertNarrows extends Control
{

	/** @phpstan-assert ApplicationForm $form */
	private function assertContentForm(Container $form): void
	{
		if (!$form instanceof ApplicationForm) {
			throw new \RuntimeException();
		}
	}

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('assertField');

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']['assertField']); // => Nette\Forms\Controls\TextInput
	}

}
