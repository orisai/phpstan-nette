<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Parity\Lifecycle\Fixtures;

use Nette\Application\Response;
use Nette\Application\UI\Form;
use Nette\Application\UI\Presenter;
use ReflectionMethod;

final class LifecycleProbePresenter extends Presenter
{

	/** @var list<string> */
	public array $log = [];

	/** @var array<string, callable(self): void> */
	public array $hooks = [];

	// Deliberately NOT $log: findLayoutTemplateFile() fires during template RENDERING, long after
	// run() returns, and LifecycleOrderParityTest pins $log as an exact sequence.
	/** @var list<string> */
	public array $layoutLookups = [];

	public function findLayoutTemplateFile(): ?string
	{
		$this->layoutLookups[] = 'findLayoutTemplateFile(view=' . $this->getView() . ')';

		return parent::findLayoutTemplateFile();
	}

	/**
	 * @param mixed $element
	 */
	public function checkRequirements($element): void
	{
		$this->log[] = $element instanceof ReflectionMethod
			? 'checkRequirements(' . $element->getName() . ')'
			: 'checkRequirements(class)';
		parent::checkRequirements($element);
	}

	protected function startup(): void
	{
		parent::startup();
		$this->log[] = 'startup';
		$this->callHook('startup');
	}

	public function actionProbe(): void
	{
		$this->log[] = 'actionProbe';
		$this->callHook('action');
	}

	public function actionSwitched(): void
	{
		$this->log[] = 'actionSwitched';
	}

	public function handleProbe(): void
	{
		$this->log[] = 'handleProbe';
		$this->callHook('signal');
	}

	protected function beforeRender(): void
	{
		parent::beforeRender();
		$this->log[] = 'beforeRender';
		$this->callHook('beforeRender');
	}

	public function renderProbe(): void
	{
		$this->log[] = 'renderProbe';
		$this->callHook('render');
	}

	public function renderAlt(): void
	{
		$this->log[] = 'renderAlt';
	}

	public function renderSwitched(): void
	{
		$this->log[] = 'renderSwitched';
	}

	public function renderLayoutblock(): void
	{
	}

	public function renderLayoutdefine(): void
	{
	}

	public function renderLayoutsnippet(): void
	{
	}

	public function renderLayoutblockless(): void
	{
	}

	public function renderLayoutnone(): void
	{
	}

	public function renderLayoutexplicit(): void
	{
	}

	public function renderLayoutauto(): void
	{
	}

	public function renderLayoutdynamic(): void
	{
		$this->getTemplate()->chosen = null;
	}

	protected function afterRender(): void
	{
		parent::afterRender();
		$this->log[] = 'afterRender';
		$this->callHook('afterRender');
	}

	/**
	 * @return array<string>
	 */
	public function formatTemplateFiles(): array
	{
		$this->log[] = 'formatTemplateFiles(view=' . $this->getView() . ')';

		return parent::formatTemplateFiles();
	}

	protected function shutdown(Response $response): void
	{
		parent::shutdown($response);
		$this->log[] = 'shutdown';
		$this->callHook('shutdown');
	}

	protected function createComponentProbeForm(): Form
	{
		$form = new Form();
		$form->addText('val');
		$form->addSubmit('send');
		$form->onSuccess[] = function (Form $form, array $values): void {
			$this->log[] = 'formOnSuccess(val=' . $values['val'] . ')';
			$this->callHook('formSuccess');
		};

		return $form;
	}

	private function callHook(string $phase): void
	{
		if (isset($this->hooks[$phase])) {
			($this->hooks[$phase])($this);
		}
	}

}
