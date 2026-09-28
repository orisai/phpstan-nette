<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Parity\Lifecycle;

use Tests\OriPhpstan\Nette\Integration\Latte\Parity\Lifecycle\Fixtures\LifecycleProbePresenter;

final class ViewMutationParityTest extends LifecycleParityTestCase
{

	public function testSetViewInStartupSelectsRenderMethodAndFile(): void
	{
		$presenter = $this->createPresenter();
		$presenter->hooks['startup'] = static function (LifecycleProbePresenter $p): void {
			$p->setView('alt');
		};
		$response = $this->runPresenter($presenter);

		self::assertTrue(
			$this->renderMethodDispatched($presenter, 'actionProbe'),
			'setView() in startup() still leaves action dispatch untouched - action<Action> is keyed'
				. ' by the action name, not the view',
		);
		self::assertTrue(
			$this->renderMethodDispatched($presenter, 'renderAlt'),
			'setView() in startup() still redirects render dispatch to render<NewView>()',
		);
		self::assertSame(
			$this->templatePath('alt'),
			$this->resolvedFile($response),
			'setView() in startup() still selects the template file - effective until sendTemplate resolution',
		);
	}

	public function testSetViewInActionSelectsRenderMethodAndFile(): void
	{
		$presenter = $this->createPresenter();
		$presenter->hooks['action'] = static function (LifecycleProbePresenter $p): void {
			$p->setView('alt');
		};
		$response = $this->runPresenter($presenter);

		self::assertTrue(
			$this->renderMethodDispatched($presenter, 'renderAlt'),
			'setView() in action<Action>() still redirects render dispatch to render<NewView>()',
		);
		self::assertSame(
			$this->templatePath('alt'),
			$this->resolvedFile($response),
			'setView() in action<Action>() still selects the template file - effective until sendTemplate resolution',
		);
	}

	public function testSetViewInSignalHandlerSelectsRenderMethodAndFile(): void
	{
		$presenter = $this->createPresenter();
		$presenter->hooks['signal'] = static function (LifecycleProbePresenter $p): void {
			$p->setView('alt');
		};
		$response = $this->runPresenter($presenter, ['do' => 'probe']);

		self::assertTrue(
			$this->renderMethodDispatched($presenter, 'renderAlt'),
			'setView() in a handle<Signal>() handler still redirects render dispatch to render<NewView>()',
		);
		self::assertSame(
			$this->templatePath('alt'),
			$this->resolvedFile($response),
			'setView() in a handle<Signal>() handler still selects the template file'
				. ' - effective until sendTemplate resolution',
		);
	}

	public function testSetViewInFormOnSuccessSelectsRenderMethodAndFile(): void
	{
		$presenter = $this->createPresenter();
		$presenter->hooks['formSuccess'] = static function (LifecycleProbePresenter $p): void {
			$p->setView('alt');
		};
		$response = $this->runPresenter(
			$presenter,
			[],
			['_do' => 'probeForm-submit', 'val' => 'x', 'send' => 'Save'],
		);

		self::assertTrue(
			$this->renderMethodDispatched($presenter, 'renderAlt'),
			'setView() in a form onSuccess callback (signal phase) still redirects render dispatch'
				. ' to render<NewView>()',
		);
		self::assertSame(
			$this->templatePath('alt'),
			$this->resolvedFile($response),
			'setView() in a form onSuccess callback still selects the template file'
				. ' - effective until sendTemplate resolution',
		);
	}

	public function testSetViewInBeforeRenderSelectsRenderMethodAndFile(): void
	{
		$presenter = $this->createPresenter();
		$presenter->hooks['beforeRender'] = static function (LifecycleProbePresenter $p): void {
			$p->setView('alt');
		};
		$response = $this->runPresenter($presenter);

		self::assertTrue(
			$this->renderMethodDispatched($presenter, 'renderAlt'),
			'setView() in beforeRender() still redirects render dispatch - render<View> is resolved'
				. ' only AFTER beforeRender() returns',
		);
		self::assertSame(
			$this->templatePath('alt'),
			$this->resolvedFile($response),
			'setView() in beforeRender() still selects the template file - effective until sendTemplate resolution',
		);
	}

	public function testSetViewInRenderMethodChangesFileButNotDispatch(): void
	{
		$presenter = $this->createPresenter();
		$presenter->hooks['render'] = static function (LifecycleProbePresenter $p): void {
			$p->setView('alt');
		};
		$response = $this->runPresenter($presenter);

		self::assertFalse(
			$this->renderMethodDispatched($presenter, 'renderAlt'),
			'setView() inside render<View>() can no longer re-dispatch - the render method was already'
				. ' selected from the pre-mutation view',
		);
		self::assertSame(
			$this->templatePath('alt'),
			$this->resolvedFile($response),
			'setView() inside render<View>() STILL selects the template file - file resolution happens'
				. ' later, at sendTemplate time',
		);
	}

	public function testSetViewInAfterRenderStillSelectsFile(): void
	{
		$presenter = $this->createPresenter();
		$presenter->hooks['afterRender'] = static function (LifecycleProbePresenter $p): void {
			$p->setView('alt');
		};
		$response = $this->runPresenter($presenter);

		self::assertSame(
			$this->templatePath('alt'),
			$this->resolvedFile($response),
			'setView() in afterRender() STILL selects the template file - the window closes only at'
				. ' sendTemplate resolution, which runs after afterRender()',
		);
	}

	public function testSetViewInShutdownIsTooLateForFile(): void
	{
		$presenter = $this->createPresenter();
		$presenter->hooks['shutdown'] = static function (LifecycleProbePresenter $p): void {
			$p->setView('alt');
		};
		$response = $this->runPresenter($presenter);

		self::assertSame('alt', $presenter->getView(), 'the shutdown() hook itself did run and mutate the view');
		self::assertSame(
			$this->templatePath('probe'),
			$this->resolvedFile($response),
			'setView() in shutdown() can no longer affect the template file - the view->file resolution'
				. ' via findTemplateFile() already ran at sendTemplate and the view is never consulted again'
				. ' (the file itself stays mutable, see SetFileParityTest)',
		);
	}

	public function testChangeActionInStartupRedispatchesActionRenderAndFile(): void
	{
		$presenter = $this->createPresenter();
		$presenter->hooks['startup'] = static function (LifecycleProbePresenter $p): void {
			$p->changeAction('switched');
		};
		$response = $this->runPresenter($presenter);

		self::assertFalse(
			$this->renderMethodDispatched($presenter, 'actionProbe'),
			'changeAction() in startup() still redirects ACTION dispatch - action<Action> is read only'
				. ' after startup() returns, so the original action method never runs',
		);
		self::assertTrue(
			$this->renderMethodDispatched($presenter, 'actionSwitched'),
			'changeAction() in startup() still dispatches the NEW action<Action> method',
		);
		self::assertTrue(
			$this->renderMethodDispatched($presenter, 'renderSwitched'),
			'changeAction() in startup() still redirects render dispatch via the rewritten view',
		);
		self::assertSame(
			$this->templatePath('switched'),
			$this->resolvedFile($response),
			'changeAction() in startup() still selects the template file via the rewritten view',
		);
	}

	public function testChangeActionInActionRedirectsRenderAndFileButNotActionDispatch(): void
	{
		$presenter = $this->createPresenter();
		$presenter->hooks['action'] = static function (LifecycleProbePresenter $p): void {
			$p->changeAction('switched');
		};
		$response = $this->runPresenter($presenter);

		self::assertFalse(
			$this->renderMethodDispatched($presenter, 'actionSwitched'),
			'changeAction() inside action<Action>() can no longer re-dispatch the action method'
				. ' - that dispatch already happened',
		);
		self::assertTrue(
			$this->renderMethodDispatched($presenter, 'renderSwitched'),
			'changeAction() inside action<Action>() still redirects render dispatch via the rewritten view',
		);
		self::assertSame(
			$this->templatePath('switched'),
			$this->resolvedFile($response),
			'changeAction() inside action<Action>() still selects the template file via the rewritten view',
		);
	}

	public function testChangeActionInSignalHandlerRedirectsRenderAndFile(): void
	{
		$presenter = $this->createPresenter();
		$presenter->hooks['signal'] = static function (LifecycleProbePresenter $p): void {
			$p->changeAction('switched');
		};
		$response = $this->runPresenter($presenter, ['do' => 'probe']);

		self::assertTrue(
			$this->renderMethodDispatched($presenter, 'renderSwitched'),
			'changeAction() in a handle<Signal>() handler still redirects render dispatch via the rewritten view',
		);
		self::assertSame(
			$this->templatePath('switched'),
			$this->resolvedFile($response),
			'changeAction() in a handle<Signal>() handler still selects the template file via the rewritten view',
		);
	}

	public function testChangeActionInFormOnSuccessRedirectsRenderAndFile(): void
	{
		$presenter = $this->createPresenter();
		$presenter->hooks['formSuccess'] = static function (LifecycleProbePresenter $p): void {
			$p->changeAction('switched');
		};
		$response = $this->runPresenter(
			$presenter,
			[],
			['_do' => 'probeForm-submit', 'val' => 'x', 'send' => 'Save'],
		);

		self::assertTrue(
			$this->renderMethodDispatched($presenter, 'renderSwitched'),
			'changeAction() in a form onSuccess callback still redirects render dispatch via the rewritten view',
		);
		self::assertSame(
			$this->templatePath('switched'),
			$this->resolvedFile($response),
			'changeAction() in a form onSuccess callback still selects the template file via the rewritten view',
		);
	}

	public function testChangeActionInBeforeRenderRedirectsRenderAndFile(): void
	{
		$presenter = $this->createPresenter();
		$presenter->hooks['beforeRender'] = static function (LifecycleProbePresenter $p): void {
			$p->changeAction('switched');
		};
		$response = $this->runPresenter($presenter);

		self::assertTrue(
			$this->renderMethodDispatched($presenter, 'renderSwitched'),
			'changeAction() in beforeRender() still redirects render dispatch via the rewritten view',
		);
		self::assertSame(
			$this->templatePath('switched'),
			$this->resolvedFile($response),
			'changeAction() in beforeRender() still selects the template file via the rewritten view',
		);
	}

	public function testChangeActionInRenderMethodChangesFileButNotDispatch(): void
	{
		$presenter = $this->createPresenter();
		$presenter->hooks['render'] = static function (LifecycleProbePresenter $p): void {
			$p->changeAction('switched');
		};
		$response = $this->runPresenter($presenter);

		self::assertFalse(
			$this->renderMethodDispatched($presenter, 'renderSwitched'),
			'changeAction() inside render<View>() can no longer re-dispatch the render method',
		);
		self::assertSame(
			$this->templatePath('switched'),
			$this->resolvedFile($response),
			'changeAction() inside render<View>() STILL selects the template file - file resolution'
				. ' happens later, at sendTemplate time',
		);
	}

	public function testChangeActionInShutdownIsTooLateForFile(): void
	{
		$presenter = $this->createPresenter();
		$presenter->hooks['shutdown'] = static function (LifecycleProbePresenter $p): void {
			$p->changeAction('switched');
		};
		$response = $this->runPresenter($presenter);

		self::assertSame(
			'switched',
			$presenter->getAction(),
			'the shutdown() hook itself did run and mutate the action',
		);
		self::assertSame(
			$this->templatePath('probe'),
			$this->resolvedFile($response),
			'changeAction() in shutdown() can no longer affect the template file - the view->file resolution'
				. ' already ran at sendTemplate and the view is never consulted again'
				. ' (the file itself stays mutable, see SetFileParityTest)',
		);
	}

	public function testSetViewThenChangeActionInSamePhaseChangeActionWins(): void
	{
		$presenter = $this->createPresenter();
		$presenter->hooks['beforeRender'] = static function (LifecycleProbePresenter $p): void {
			$p->setView('alt');
			$p->changeAction('switched');
		};
		$response = $this->runPresenter($presenter);

		self::assertSame(
			'switched',
			$presenter->getView(),
			'changeAction() still rewrites BOTH action and view, overwriting an earlier setView() in the same phase',
		);
		self::assertTrue(
			$this->renderMethodDispatched($presenter, 'renderSwitched'),
			'after setView() then changeAction(), render dispatch follows the changeAction() view',
		);
		self::assertSame(
			$this->templatePath('switched'),
			$this->resolvedFile($response),
			'after setView() then changeAction(), the template file follows the changeAction() view'
				. ' - last write wins',
		);
	}

	public function testChangeActionThenSetViewInSamePhaseSetViewWins(): void
	{
		$presenter = $this->createPresenter();
		$presenter->hooks['beforeRender'] = static function (LifecycleProbePresenter $p): void {
			$p->changeAction('switched');
			$p->setView('alt');
		};
		$response = $this->runPresenter($presenter);

		self::assertSame(
			'switched',
			$presenter->getAction(),
			'a later setView() does not touch the action rewritten by changeAction()',
		);
		self::assertTrue(
			$this->renderMethodDispatched($presenter, 'renderAlt'),
			'after changeAction() then setView(), render dispatch follows the setView() view - last write wins',
		);
		self::assertSame(
			$this->templatePath('alt'),
			$this->resolvedFile($response),
			'after changeAction() then setView(), the template file follows the setView() view - last write wins',
		);
	}

}
