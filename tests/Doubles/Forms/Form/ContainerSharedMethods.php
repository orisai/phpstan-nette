<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\Form;

use Tests\OriPhpstan\Nette\Doubles\Forms\Control\ReCaptchaField;
use Nette\Forms\Container;
use Nette\Forms\Controls\BaseControl;
use Nette\Forms\Controls\TextInput;
use Nette\Forms\Form;
use function Tests\OriPhpstan\Nette\Doubles\Forms\t;

/**
 * @method ReCaptchaField addReCaptcha(string $name, string $label, bool $required = true, string $message = 'Are you bot?')
 */
trait ContainerSharedMethods
{

	/**
	 * @see Container::addContainer()
	 */
	public function addContainer($name): FormContainer
	{
		$control = new FormContainer();
		$control->currentGroup = $this->currentGroup;
		if ($this->currentGroup !== null) {
			$this->currentGroup->add($control);
		}

		return $this[$name] = $control;
	}

	public function addEmail(
		string $name,
		$label = null,
		?int $cols = null,
		?int $maxLength = 255
	): TextInput
	{
		$control = $this->addText($name, $label, $cols, $maxLength);
		$control->type = 'email';
		$control->addCondition(Form::FILLED)
			->addRule(Form::EMAIL, t('Zadejte validní emailovou adresu.'));

		return $control;
	}

	/**
	 * @param callable(FormContainer): void $factory
	 * @param int<0, max> $createDefault
	 */
	public function addDynamic(
		string $name,
		callable $factory,
		int $createDefault = 0,
		bool $forceDefault = false
	): CustomReplicatorContainer
	{
		$control = new CustomReplicatorContainer($factory, $createDefault, $forceDefault);
		$control->containerClass = FormContainer::class;
		$control->currentGroup = $this->currentGroup;

		return $this[$name] = $control;
	}

	public function addSubmit(string $name, $caption = null): CustomSubmitButton
	{
		return $this[$name] = new CustomSubmitButton($caption);
	}

	/**
	 * @form-disabler
	 */
	public function setDisabled(): void
	{
		foreach ($this->getComponents(true, BaseControl::class) as $control) {
			$control->setDisabled();
		}
	}

}
