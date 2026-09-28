<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

use Latte\Engine;
use Nette\SmartObject;
use function count;

// The spellings that reach a child control's form without naming it on the mutating statement:
// through a getter, through a chain of local copies, by handing it to a helper, by handing it on
// twice, inside a literal array, through a free function and through an event property. Each adds a
// component the reached form's builder never mentions - unlike the read-only hand-overs, which add
// nothing and must leave their forms closed.
final class IndirectMutator
{

	use SmartObject;

	/** @var array<callable> */
	public array $onReach = [];

	public function createComponentGetterCtrl(): GetterReachedRenderer
	{
		return new GetterReachedRenderer();
	}

	public function createComponentAliasCtrl(): AliasReachedRenderer
	{
		return new AliasReachedRenderer();
	}

	public function createComponentHelperCtrl(): HelperReachedRenderer
	{
		return new HelperReachedRenderer();
	}

	public function createComponentChainCtrl(): ChainReachedRenderer
	{
		return new ChainReachedRenderer();
	}

	public function createComponentReadOnlyCtrl(): ReadOnlyReachedRenderer
	{
		return new ReadOnlyReachedRenderer();
	}

	public function createComponentSubtypedCtrl(): SubtypedBaseControl
	{
		return new SubtypedChildControl();
	}

	public function createComponentInheritedCtrl(): InheritedChildControl
	{
		return new InheritedChildControl();
	}

	public function createComponentArrayCtrl(): ArrayReachedRenderer
	{
		return new ArrayReachedRenderer();
	}

	public function createComponentArrayReadOnlyCtrl(): ArrayReadOnlyReachedRenderer
	{
		return new ArrayReadOnlyReachedRenderer();
	}

	public function createComponentUnscannedCtrl(): UnscannedCalleeRenderer
	{
		return new UnscannedCalleeRenderer();
	}

	public function createComponentCopyCtrl(): CopyReachedRenderer
	{
		return new CopyReachedRenderer();
	}

	public function createComponentFreeCtrl(): FreeReachedRenderer
	{
		return new FreeReachedRenderer();
	}

	public function createComponentEventCtrl(): EventReachedRenderer
	{
		return new EventReachedRenderer();
	}

	public function actionSubtyped(): void
	{
		$this['subtypedCtrl']['subtypedForm']->addHidden('late');
	}

	public function actionInherited(): void
	{
		$this['inheritedCtrl']['inheritedForm']->addHidden('late');
	}

	public function actionGetter(): void
	{
		$this['getterCtrl']->getForm()->addHidden('late');
	}

	public function actionAlias(): void
	{
		$first = $this['aliasCtrl']['aliasForm'];
		$second = $first;
		$second->addHidden('late');
	}

	public function actionHelper(): void
	{
		$this->decorate($this['helperCtrl']['helperForm']);
	}

	public function actionChain(): void
	{
		$this->relay($this['chainCtrl']['chainForm']);
	}

	public function actionReadOnly(): void
	{
		$this->inspect($this['readOnlyCtrl']['readOnlyForm']);
	}

	public function actionArrayWrapped(): void
	{
		$this->registerAll([$this['arrayCtrl']['arrayForm']]);
	}

	public function actionArrayReadOnly(): void
	{
		$this->countAll([$this['arrayReadOnlyCtrl']['arrayReadOnlyForm']]);
	}

	// The corpus's own addProvider('formsStack', [$this['form']]) spelling: the callee is a VENDOR
	// method, so no body is ever scanned and the hand-over stays undecidable rather than mutating.
	public function actionUnscannedCallee(): void
	{
		(new Engine())->addProvider('formsStack', [$this['unscannedCtrl']['unscannedForm']]);
	}

	public function actionParamCopy(): void
	{
		$this->decorateThroughACopy($this['copyCtrl']['copyForm']);
	}

	public function actionFreeFunction(): void
	{
		decorateFreeForm($this['freeCtrl']['freeForm']);
	}

	public function actionEvent(): void
	{
		$this->onReach($this['eventCtrl']['eventForm']);
	}

	private function decorate(PairingForm $form): void
	{
		$form->addHidden('late');
	}

	private function relay(PairingForm $form): void
	{
		$this->decorate($form);
	}

	private function inspect(PairingForm $form): void
	{
		$form->getValues();
	}

	/**
	 * @param array<PairingForm> $forms
	 */
	private function registerAll(array $forms): void
	{
		foreach ($forms as $form) {
			$form->addHidden('late');
		}
	}

	/**
	 * @param array<PairingForm> $forms
	 */
	private function countAll(array $forms): int
	{
		return count($forms);
	}

	private function decorateThroughACopy(PairingForm $form): void
	{
		$copy = $form;
		$copy->addHidden('late');
	}

}
