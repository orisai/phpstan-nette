<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;
use Nette\Application\UI\Control;
use function PHPStan\dumpType;

final class ContainerRefDynamicReassignMirror extends Control
{

	protected function createComponentForm(): ApplicationForm
	{
		$form = new ApplicationForm();

		$monitoredAreas = $form->addContainer('monitored_areas');
		$monitoredAreas->addCheckbox('use_highlight');
		$monitoredAreas->addInteger('waves_count');

		$monitoredAreas = $monitoredAreas->addDynamic('areas', static function (FormContainer $container): void {
			$container->addSelect('question_id');
		});

		return $form;
	}

	public function go(): void
	{
		dumpType($this['form']['monitored_areas']['use_highlight']); // => Nette\Forms\Controls\Checkbox
		dumpType($this['form']['monitored_areas']['waves_count']); // => Nette\Forms\Controls\TextInput
		dumpType($this['form']['monitored_areas']['areas'][0]['question_id']); // => Nette\Forms\Controls\SelectBox
	}

}
