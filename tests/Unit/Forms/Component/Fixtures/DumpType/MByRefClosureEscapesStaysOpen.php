<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Closure;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class MByRefClosureEscapesStaysOpen extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('direct');

		$build = function () use (&$form): void {
			$form->addText('viaEscape');
		};
		$this->register($build);

		return $form;
	}

	private function register(Closure $closure): void
	{
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{direct: string, ...<mixed>}
	}

}
