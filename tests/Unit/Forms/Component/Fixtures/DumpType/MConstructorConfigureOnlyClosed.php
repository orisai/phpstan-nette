<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm;
use Nette\Application\UI\Control;
use Nette\Forms\Controls\TextInput;
use function PHPStan\dumpType;

/**
 * A constructor whose only unrecognised statement is a call to a same-class *private* helper
 * with a provably inert body (the ContentForm production pattern: `parent::__construct();
 * $this->configureRender();` where configureRender only touches the renderer) does not open
 * the shape. Contrasted against a same-shaped private helper that DOES add a control or write
 * `$this[...]` (must stay open — field extraction is not attempted through indirection) and a
 * protected helper with an otherwise-inert body (must stay open — dispatch is overridable).
 */
class ConfigureOnlyForm_MConstructorConfigureOnly extends RawForm
{

	public function __construct()
	{
		parent::__construct();
		$this->configureExtra();
	}

	private function configureExtra(): void
	{
		$renderer = $this->getRenderer();
		$renderer->wrappers['controls']['container'] = null;
	}

}

class BuildingHelperForm_MConstructorConfigureOnly extends RawForm
{

	public function __construct()
	{
		parent::__construct();
		$this->build();
	}

	private function build(): void
	{
		$this->addText('fromHelper');
	}

}

class OffsetHelperForm_MConstructorConfigureOnly extends RawForm
{

	public function __construct()
	{
		parent::__construct();
		$this->buildOffset();
	}

	private function buildOffset(): void
	{
		$this['fromOffset'] = new TextInput();
	}

}

class ProtectedHelperForm_MConstructorConfigureOnly extends RawForm
{

	public function __construct()
	{
		parent::__construct();
		$this->configureRenderProtected();
	}

	protected function configureRenderProtected(): void
	{
		$renderer = $this->getRenderer();
		$renderer->wrappers['controls']['container'] = null;
	}

}

final class ConfigureOnlyFactory_MConstructorConfigureOnly
{

	public function create(): ConfigureOnlyForm_MConstructorConfigureOnly
	{
		return new ConfigureOnlyForm_MConstructorConfigureOnly();
	}

}

final class MConstructorConfigureOnlyClosed extends Control
{

	protected function createComponentForm(): ConfigureOnlyForm_MConstructorConfigureOnly
	{
		$form = new ConfigureOnlyForm_MConstructorConfigureOnly();
		$form->onSuccess[] = [$this, 'process'];

		return $form;
	}

	public function process(ConfigureOnlyForm_MConstructorConfigureOnly $form): void
	{
		dumpType($form->getValues()); // => Nette\Utils\ArrayHash{}
	}

}

/**
 * Mirrors the real `FormFactory::createForNonApp()` residue: a remote factory returns a
 * configure-only-ctor'd form, and the caller adds its own fields directly afterward. Closing
 * the constructor lets the sole-rebind compensation trust the factory's (now empty) origin
 * shape and merge in the fields added here, instead of falling back to the fully open marker.
 */
final class MConstructorConfigureOnlyRebindClosed extends Control
{

	private ConfigureOnlyFactory_MConstructorConfigureOnly $factory;

	protected function createComponentForm(): ConfigureOnlyForm_MConstructorConfigureOnly
	{
		$form = $this->factory->create();
		$form->addText('extra');
		$form->onSuccess[] = [$this, 'process'];

		return $form;
	}

	public function process(ConfigureOnlyForm_MConstructorConfigureOnly $form): void
	{
		dumpType($form->getValues()); // => Nette\Utils\ArrayHash{extra: string}
	}

}

final class MConstructorHelperAddStaysOpen extends Control
{

	protected function createComponentForm(): BuildingHelperForm_MConstructorConfigureOnly
	{
		$form = new BuildingHelperForm_MConstructorConfigureOnly();
		$form->onSuccess[] = [$this, 'process'];

		return $form;
	}

	public function process(BuildingHelperForm_MConstructorConfigureOnly $form): void
	{
		dumpType($form->getValues()); // => Nette\Utils\ArrayHash{...<mixed>}
	}

}

final class MConstructorHelperOffsetStaysOpen extends Control
{

	protected function createComponentForm(): OffsetHelperForm_MConstructorConfigureOnly
	{
		$form = new OffsetHelperForm_MConstructorConfigureOnly();
		$form->onSuccess[] = [$this, 'process'];

		return $form;
	}

	public function process(OffsetHelperForm_MConstructorConfigureOnly $form): void
	{
		dumpType($form->getValues()); // => Nette\Utils\ArrayHash{...<mixed>}
	}

}

final class MConstructorProtectedHelperStaysOpen extends Control
{

	protected function createComponentForm(): ProtectedHelperForm_MConstructorConfigureOnly
	{
		$form = new ProtectedHelperForm_MConstructorConfigureOnly();
		$form->onSuccess[] = [$this, 'process'];

		return $form;
	}

	public function process(ProtectedHelperForm_MConstructorConfigureOnly $form): void
	{
		dumpType($form->getValues()); // => Nette\Utils\ArrayHash{...<mixed>}
	}

}

/**
 * The deny-by-default pins: an inherited (parent-declared) helper whose body adds a control
 * must not be trusted by name alone — the predicate cannot see through inherited dispatch, so
 * the shape stays open; and `new X($this)` hands the form under construction to a foreign
 * constructor (an unmodeled sink) in both the bare-expression and local-assignment spellings.
 */
class InheritedBuilderBase_MConstructorConfigureOnly extends RawForm
{

	protected function buildControls(): void
	{
		$this->addHidden('csrf');
	}

}

class InheritedBuilderForm_MConstructorConfigureOnly extends InheritedBuilderBase_MConstructorConfigureOnly
{

	public function __construct()
	{
		parent::__construct();
		$this->buildControls();
	}

}

final class CtorSink_MConstructorConfigureOnly
{

	public function __construct(RawForm $form)
	{
		$form->addText('fromSink');
	}

}

class SinkExprForm_MConstructorConfigureOnly extends RawForm
{

	public function __construct()
	{
		parent::__construct();
		new CtorSink_MConstructorConfigureOnly($this);
	}

}

class SinkAssignForm_MConstructorConfigureOnly extends RawForm
{

	public function __construct()
	{
		parent::__construct();
		$sink = new CtorSink_MConstructorConfigureOnly($this);
	}

}

final class MConstructorInheritedHelperStaysOpen extends Control
{

	protected function createComponentForm(): InheritedBuilderForm_MConstructorConfigureOnly
	{
		return new InheritedBuilderForm_MConstructorConfigureOnly();
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{...<mixed>}
	}

}

final class MConstructorNewThisSinkStaysOpen extends Control
{

	protected function createComponentForm(): SinkExprForm_MConstructorConfigureOnly
	{
		return new SinkExprForm_MConstructorConfigureOnly();
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{...<mixed>}
	}

}

final class MConstructorNewThisSinkAssignStaysOpen extends Control
{

	protected function createComponentForm(): SinkAssignForm_MConstructorConfigureOnly
	{
		return new SinkAssignForm_MConstructorConfigureOnly();
	}

	public function go(): void
	{
		dumpType($this['form']->getValues()); // => Nette\Utils\ArrayHash{...<mixed>}
	}

}
